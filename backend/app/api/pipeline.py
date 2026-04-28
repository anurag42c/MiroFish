"""
Pipeline API — End-to-end prediction pipeline endpoint.

Orchestrates the full MiroFish workflow in a single call:
  1. Ontology generation (synchronous, LLM)
  2. Graph build in Zep (async background task, polled to completion)
  3. Simulation record creation
  4. Simulation preparation (async background task, polled to completion)
  5. Simulation launch (subprocess — worker polls until it finishes)
  6. Report generation (LLM agent synthesizing simulation results)

Pipeline status values
----------------------
running             — Stages 1-4 or 6 are executing in the worker thread
simulation_running  — Simulation subprocess is active; worker is waiting for it
completed           — All stages done (report generated)
failed              — Any stage encountered an unrecoverable error

Stage status values: pending | running | completed | failed

State is persisted to disk (uploads/projects/<pipeline_id>/pipeline.json) so
the /status endpoint works correctly even after a server restart.
"""

import os
import json
import time
import uuid
import threading
import traceback
from datetime import datetime
from typing import Optional

from flask import request, jsonify

from . import pipeline_bp
from ..config import Config
from ..models.task import TaskManager, TaskStatus
from ..models.project import ProjectManager, ProjectStatus
from ..services.ontology_generator import OntologyGenerator
from ..services.graph_builder import GraphBuilderService
from ..services.simulation_manager import SimulationManager, SimulationStatus
from ..services.report_agent import ReportAgent, ReportManager
from ..services.simulation_runner import SimulationRunner, RunnerStatus
from ..services.text_processor import TextProcessor
from ..utils.file_parser import FileParser
from ..utils.logger import get_logger
from ..utils.locale import t, get_locale, set_locale

logger = get_logger('mirofish.api.pipeline')

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------

PIPELINE_STAGES = [
    "ontology_generate",
    "graph_build",
    "simulation_create",
    "simulation_prepare",
    "simulation_run",
    "report_generate",
]

# Overall progress range attributed to each stage (start%, end%)
STAGE_PROGRESS = {
    "ontology_generate":  (0,  10),
    "graph_build":        (10, 45),
    "simulation_create":  (45, 50),
    "simulation_prepare": (50, 75),
    "simulation_run":     (75, 90),
    "report_generate":    (90, 100),
}

# Stage-level status values
STAGE_STATUS_PENDING   = "pending"
STAGE_STATUS_RUNNING   = "running"
STAGE_STATUS_COMPLETED = "completed"
STAGE_STATUS_FAILED    = "failed"

# Pipeline-level status values
PIPELINE_STATUS_RUNNING            = "running"            # stages 1-4 in progress
PIPELINE_STATUS_SIMULATION_RUNNING = "simulation_running" # simulation subprocess active
PIPELINE_STATUS_COMPLETED          = "completed"          # simulation finished/stopped
PIPELINE_STATUS_FAILED             = "failed"             # unrecoverable error

# Runner statuses that indicate the simulation subprocess has finished
_RUNNER_DONE_STATUSES = {
    RunnerStatus.COMPLETED,
    RunnerStatus.STOPPED,
    RunnerStatus.FAILED,
}


# ---------------------------------------------------------------------------
# Persistent state helpers
# ---------------------------------------------------------------------------

def _pipeline_file(pipeline_id: str) -> str:
    """Return the path to the persisted pipeline state JSON file."""
    project_dir = os.path.join(
        os.path.dirname(__file__),
        "../../uploads/projects",
        pipeline_id,
    )
    os.makedirs(project_dir, exist_ok=True)
    return os.path.join(project_dir, "pipeline.json")


def _save_pipeline(state: dict) -> None:
    """Write pipeline state to disk."""
    state["updated_at"] = datetime.now().isoformat()
    with open(_pipeline_file(state["pipeline_id"]), "w", encoding="utf-8") as f:
        json.dump(state, f, ensure_ascii=False, indent=2)


def _load_pipeline(pipeline_id: str) -> Optional[dict]:
    """Load pipeline state from disk. Returns None if not found."""
    path = _pipeline_file(pipeline_id)
    if not os.path.exists(path):
        return None
    with open(path, "r", encoding="utf-8") as f:
        return json.load(f)


def _initial_pipeline_state(
    pipeline_id: str,
    project_name: str,
    simulation_requirement: str,
) -> dict:
    now = datetime.now().isoformat()
    return {
        "pipeline_id":            pipeline_id,
        "project_id":             pipeline_id,
        "project_name":           project_name,
        "simulation_requirement": simulation_requirement,
        "simulation_id":          None,
        "pipeline_status":        PIPELINE_STATUS_RUNNING,
        "current_stage":          "ontology_generate",
        "progress":               0,
        "stages_status":          {s: STAGE_STATUS_PENDING for s in PIPELINE_STAGES},
        "graph_build_task_id":    None,
        "prepare_task_id":        None,
        "report_id":              None,
        "error":                  None,
        "created_at":             now,
        "updated_at":             now,
    }


def _set_stage(state: dict, stage: str, status: str) -> None:
    """Update a stage status and adjust global progress accordingly."""
    state["stages_status"][stage] = status
    if status == STAGE_STATUS_RUNNING:
        state["current_stage"] = stage
        start, _ = STAGE_PROGRESS[stage]
        state["progress"] = start
    elif status == STAGE_STATUS_COMPLETED:
        _, end = STAGE_PROGRESS[stage]
        state["progress"] = end
    elif status == STAGE_STATUS_FAILED:
        state["pipeline_status"] = PIPELINE_STATUS_FAILED
    _save_pipeline(state)


def _allowed_file(filename: str) -> bool:
    if not filename or "." not in filename:
        return False
    ext = os.path.splitext(filename)[1].lower().lstrip(".")
    return ext in Config.ALLOWED_EXTENSIONS


# ---------------------------------------------------------------------------
# Background worker
# ---------------------------------------------------------------------------

def _run_pipeline_worker(
    state: dict,
    all_text: str,
    document_texts: list,
    simulation_requirement: str,
    additional_context: str,
    chunk_size: int,
    chunk_overlap: int,
    enable_twitter: bool,
    enable_reddit: bool,
    platform: str,
    max_rounds: Optional[int],
    parallel_profile_count: int,
    enable_graph_memory_update: bool,
    locale: str,
):
    set_locale(locale)
    project_id = state["pipeline_id"]
    task_manager = TaskManager()

    try:
        # Stage 1 — Generate ontology
        _set_stage(state, "ontology_generate", STAGE_STATUS_RUNNING)
        logger.info(f"[Pipeline {project_id}] Stage 1: Generating ontology")

        generator = OntologyGenerator()
        ontology = generator.generate(
            document_texts=document_texts,
            simulation_requirement=simulation_requirement,
            additional_context=additional_context if additional_context else None,
        )

        project = ProjectManager.get_project(project_id)
        project.ontology = {
            "entity_types": ontology.get("entity_types", []),
            "edge_types":   ontology.get("edge_types", []),
        }
        project.analysis_summary = ontology.get("analysis_summary", "")
        project.status = ProjectStatus.ONTOLOGY_GENERATED
        ProjectManager.save_project(project)

        _set_stage(state, "ontology_generate", STAGE_STATUS_COMPLETED)
        logger.info(
            f"[Pipeline {project_id}] Ontology done — "
            f"{len(project.ontology['entity_types'])} entity types"
        )

        # Stage 2 — Build knowledge graph in Zep
        _set_stage(state, "graph_build", STAGE_STATUS_RUNNING)
        logger.info(f"[Pipeline {project_id}] Stage 2: Building graph in Zep")

        if not Config.ZEP_API_KEY:
            raise ValueError("ZEP_API_KEY is not configured; cannot build the graph.")

        builder = GraphBuilderService()
        graph_task_id = builder.build_graph_async(
            text=all_text,
            ontology=project.ontology,
            graph_name=f"MiroFish — {project.name}",
            chunk_size=chunk_size,
            chunk_overlap=chunk_overlap,
        )

        state["graph_build_task_id"] = graph_task_id
        _save_pipeline(state)

        # Poll every 5 seconds until the Zep graph build task finishes
        while True:
            task = task_manager.get_task(graph_task_id)
            if task is None:
                raise RuntimeError(
                    f"Graph build task {graph_task_id} not found in TaskManager"
                )

            # Reflect partial progress inside this stage band
            g_start, g_end = STAGE_PROGRESS["graph_build"]
            state["progress"] = g_start + int((g_end - g_start) * task.progress / 100)
            _save_pipeline(state)

            if task.status == TaskStatus.COMPLETED:
                graph_id = task.result.get("graph_id")
                project = ProjectManager.get_project(project_id)
                project.graph_id            = graph_id
                project.graph_build_task_id = graph_task_id
                project.status              = ProjectStatus.GRAPH_COMPLETED
                ProjectManager.save_project(project)
                break
            elif task.status == TaskStatus.FAILED:
                raise RuntimeError(f"Graph build failed: {task.error}")

            time.sleep(5)

        _set_stage(state, "graph_build", STAGE_STATUS_COMPLETED)
        logger.info(f"[Pipeline {project_id}] Graph built: {graph_id}")

        # Stage 3 — Create simulation record
        _set_stage(state, "simulation_create", STAGE_STATUS_RUNNING)
        logger.info(f"[Pipeline {project_id}] Stage 3: Creating simulation record")

        sim_manager = SimulationManager()
        sim_state = sim_manager.create_simulation(
            project_id=project_id,
            graph_id=graph_id,
            enable_twitter=enable_twitter,
            enable_reddit=enable_reddit,
        )
        simulation_id          = sim_state.simulation_id
        state["simulation_id"] = simulation_id
        _save_pipeline(state)

        _set_stage(state, "simulation_create", STAGE_STATUS_COMPLETED)
        logger.info(f"[Pipeline {project_id}] Simulation created: {simulation_id}")

        # Stage 4 — Prepare simulation (profiles + config)
        _set_stage(state, "simulation_prepare", STAGE_STATUS_RUNNING)
        logger.info(
            f"[Pipeline {project_id}] Stage 4: Preparing simulation profiles/config"
        )

        prepare_task_id          = task_manager.create_task(
            task_type="simulation_prepare",
            metadata={"simulation_id": simulation_id, "project_id": project_id},
        )
        state["prepare_task_id"] = prepare_task_id
        _save_pipeline(state)

        task_manager.update_task(
            prepare_task_id,
            status=TaskStatus.PROCESSING,
            progress=0,
            message="Starting simulation preparation",
        )

        p_start, p_end = STAGE_PROGRESS["simulation_prepare"]

        def progress_callback(stage, progress, message, **kwargs):
            # Map internal 4-sub-stage progress to the global band
            sub_weights = {
                "reading":             (0,  20),
                "generating_profiles": (20, 70),
                "generating_config":   (70, 90),
                "copying_scripts":     (90, 100),
            }
            s, e      = sub_weights.get(stage, (0, 100))
            t_prog    = int(s + (e - s) * progress / 100)
            task_manager.update_task(prepare_task_id, progress=t_prog, message=message)

            # Advance global pipeline progress within the preparation band
            state["progress"] = p_start + int((p_end - p_start) * t_prog / 100)
            _save_pipeline(state)

        document_text = ProjectManager.get_extracted_text(project_id) or ""
        result_state  = sim_manager.prepare_simulation(
            simulation_id=simulation_id,
            simulation_requirement=simulation_requirement,
            document_text=document_text,
            parallel_profile_count=parallel_profile_count,
            progress_callback=progress_callback,
        )

        if result_state.status == SimulationStatus.FAILED:
            raise RuntimeError(f"Simulation preparation failed: {result_state.error}")

        task_manager.complete_task(prepare_task_id, result=result_state.to_simple_dict())
        _set_stage(state, "simulation_prepare", STAGE_STATUS_COMPLETED)
        logger.info(f"[Pipeline {project_id}] Simulation prepared: {simulation_id}")

        # Stage 5 — Launch simulation subprocess
        #
        # IMPORTANT: start_simulation() only *launches* the OASIS process.
        # The simulation keeps running independently through many rounds.
        # The pipeline moves to PIPELINE_STATUS_SIMULATION_RUNNING, NOT completed.
        # The /status endpoint resolves the final status from the runner state.
        _set_stage(state, "simulation_run", STAGE_STATUS_RUNNING)
        logger.info(
            f"[Pipeline {project_id}] Stage 5: Launching simulation subprocess"
        )

        graph_id_for_memory = sim_state.graph_id if enable_graph_memory_update else None

        SimulationRunner.start_simulation(
            simulation_id=simulation_id,
            platform=platform,
            max_rounds=max_rounds,
            enable_graph_memory_update=enable_graph_memory_update,
            graph_id=graph_id_for_memory,
        )

        # Persist simulation status as RUNNING
        refreshed = sim_manager.get_simulation(simulation_id)
        if refreshed:
            refreshed.status = SimulationStatus.RUNNING
            sim_manager._save_simulation_state(refreshed)

        # Mark simulation stage as running — subprocess has launched but is not done yet.
        # pipeline_status -> simulation_running so /status can display live runner info.
        # This worker thread stays alive and polls until the subprocess finishes,
        # then proceeds directly to Stage 6 (report generation).
        state["stages_status"]["simulation_run"] = STAGE_STATUS_RUNNING
        state["progress"]                        = 75
        state["pipeline_status"]                 = PIPELINE_STATUS_SIMULATION_RUNNING
        _save_pipeline(state)

        logger.info(
            f"[Pipeline {project_id}] Simulation subprocess launched. "
            "Worker is now waiting for it to finish before generating the report..."
        )

        # Poll every 15 seconds until the runner finishes
        while True:
            run_state = SimulationRunner.get_run_state(simulation_id)
            if run_state is None or run_state.runner_status in _RUNNER_DONE_STATUSES:
                break
            time.sleep(15)

        # Re-fetch final runner state
        run_state = SimulationRunner.get_run_state(simulation_id)
        if run_state and run_state.runner_status == RunnerStatus.FAILED:
            raise RuntimeError("Simulation subprocess reported a FAILED status.")

        _set_stage(state, "simulation_run", STAGE_STATUS_COMPLETED)
        state["pipeline_status"] = PIPELINE_STATUS_RUNNING
        _save_pipeline(state)
        logger.info(f"[Pipeline {project_id}] Simulation finished. Starting report generation.")

        # Stage 6 — Generate report using ReportAgent
        _set_stage(state, "report_generate", STAGE_STATUS_RUNNING)
        logger.info(f"[Pipeline {project_id}] Stage 6: Generating report")

        report_id         = f"report_{uuid.uuid4().hex[:12]}"
        state["report_id"] = report_id
        _save_pipeline(state)

        r_start, r_end = STAGE_PROGRESS["report_generate"]

        def report_progress_callback(stage: str, progress: int, message: str) -> None:
            state["progress"] = r_start + int((r_end - r_start) * progress / 100)
            _save_pipeline(state)

        project = ProjectManager.get_project(project_id)
        agent   = ReportAgent(
            graph_id=graph_id,
            simulation_id=simulation_id,
            simulation_requirement=simulation_requirement,
        )
        report = agent.generate_report(
            progress_callback=report_progress_callback,
            report_id=report_id,
        )
        ReportManager.save_report(report)

        _set_stage(state, "report_generate", STAGE_STATUS_COMPLETED)
        state["pipeline_status"] = PIPELINE_STATUS_COMPLETED
        _save_pipeline(state)
        logger.info(
            f"[Pipeline {project_id}] Pipeline fully completed. "
            f"Report: {report_id}"
        )

    except Exception as exc:
        error_msg     = str(exc)
        current_stage = state.get("current_stage", "ontology_generate")
        logger.error(
            f"[Pipeline {project_id}] Failed at stage '{current_stage}': "
            f"{traceback.format_exc()}"
        )
        state["stages_status"][current_stage] = STAGE_STATUS_FAILED
        state["pipeline_status"]              = PIPELINE_STATUS_FAILED
        state["error"]                        = error_msg
        _save_pipeline(state)

        # Fail any in-flight prepare task
        prepare_task_id = state.get("prepare_task_id")
        if prepare_task_id:
            try:
                task = task_manager.get_task(prepare_task_id)
                if task and task.status == TaskStatus.PROCESSING:
                    task_manager.fail_task(prepare_task_id, error_msg)
            except Exception:
                pass


# ─────────────────────────────────────────────────────
# Endpoints
# ─────────────────────────────────────────────────────


@pipeline_bp.route("/start", methods=["POST"])
def start_pipeline():
    """
    Start the full end-to-end prediction pipeline.

    Accepts the same files and parameters as POST /api/graph/ontology/generate.
    Returns immediately (202) with a pipeline_id; the workflow runs in background.

    Request: multipart/form-data
        files                       Required. PDF/MD/TXT files (one or more).
        simulation_requirement      Required. Prediction goal description.
        project_name                Optional. Display name (default: "Unnamed Project").
        additional_context          Optional. Extra context for ontology generation.
        chunk_size                  Optional. Text chunk size (default: 500).
        chunk_overlap               Optional. Chunk overlap (default: 50).
        enable_twitter              Optional. Enable Twitter platform (default: true).
        enable_reddit               Optional. Enable Reddit platform (default: true).
        platform                    Optional. "twitter" | "reddit" | "parallel" (default: parallel).
        max_rounds                  Optional. Cap on simulation rounds.
        parallel_profile_count      Optional. Parallel LLM profile generation (default: 5).
        enable_graph_memory_update  Optional. Push agent actions to Zep graph (default: false).

    Response 202:
        {
            "success": true,
            "data": {
                "pipeline_id": "proj_xxxxxxxxxxxx",
                "project_id":  "proj_xxxxxxxxxxxx",
                "status":      "running",
                "message":     "...",
                "status_url":  "/api/pipeline/proj_xxxx/status"
            }
        }
    """
    try:
        # -- Text parameters
        simulation_requirement = request.form.get("simulation_requirement", "").strip()
        if not simulation_requirement:
            return jsonify({"success": False, "error": t("api.requireSimulationRequirement")}), 400

        project_name               = request.form.get("project_name", "Unnamed Project")
        additional_context         = request.form.get("additional_context", "")
        chunk_size                 = int(request.form.get("chunk_size",  Config.DEFAULT_CHUNK_SIZE))
        chunk_overlap              = int(request.form.get("chunk_overlap", Config.DEFAULT_CHUNK_OVERLAP))
        enable_twitter             = request.form.get("enable_twitter", "true").lower() == "true"
        enable_reddit              = request.form.get("enable_reddit",  "true").lower() == "true"
        platform                   = request.form.get("platform", "parallel")
        parallel_profile_count     = int(request.form.get("parallel_profile_count", 5))
        enable_graph_memory_update = (
            request.form.get("enable_graph_memory_update", "false").lower() == "true"
        )

        max_rounds: Optional[int] = None
        max_rounds_raw = request.form.get("max_rounds")
        if max_rounds_raw:
            max_rounds = int(max_rounds_raw)
            if max_rounds <= 0:
                return jsonify({"success": False, "error": t("api.maxRoundsPositive")}), 400

        if platform not in ("twitter", "reddit", "parallel"):
            return jsonify({"success": False, "error": t("api.invalidPlatform", platform=platform)}), 400

        # -- Files
        uploaded_files = request.files.getlist("files")
        if not uploaded_files or all(not f.filename for f in uploaded_files):
            return jsonify({"success": False, "error": t("api.requireFileUpload")}), 400

        # -- Create project record
        project    = ProjectManager.create_project(name=project_name)
        project.simulation_requirement = simulation_requirement
        project_id = project.project_id
        logger.info(f"[Pipeline] New project created: {project_id}")

        # -- Extract text from uploaded files
        document_texts: list = []
        all_text             = ""

        for file in uploaded_files:
            if file and file.filename and _allowed_file(file.filename):
                file_info = ProjectManager.save_file_to_project(
                    project_id, file, file.filename
                )
                project.files.append({
                    "filename": file_info["original_filename"],
                    "size":     file_info["size"],
                })
                text = TextProcessor.preprocess_text(
                    FileParser.extract_text(file_info["path"])
                )
                document_texts.append(text)
                all_text += f"\n\n=== {file_info['original_filename']} ===\n{text}"

        if not document_texts:
            ProjectManager.delete_project(project_id)
            return jsonify({"success": False, "error": t("api.noDocProcessed")}), 400

        project.total_text_length = len(all_text)
        ProjectManager.save_extracted_text(project_id, all_text)
        ProjectManager.save_project(project)

        # -- Create and persist initial pipeline state
        state = _initial_pipeline_state(project_id, project_name, simulation_requirement)
        _save_pipeline(state)

        # -- Launch background worker thread
        current_locale = get_locale()
        thread = threading.Thread(
            target=_run_pipeline_worker,
            args=(
                state,
                all_text,
                document_texts,
                simulation_requirement,
                additional_context,
                chunk_size,
                chunk_overlap,
                enable_twitter,
                enable_reddit,
                platform,
                max_rounds,
                parallel_profile_count,
                enable_graph_memory_update,
                current_locale,
            ),
            daemon=True,
        )
        thread.start()

        return jsonify({
            "success": True,
            "data": {
                "pipeline_id": project_id,
                "project_id":  project_id,
                "status":      PIPELINE_STATUS_RUNNING,
                "message":     "Pipeline started. Poll status_url for progress.",
                "status_url":  f"/api/pipeline/{project_id}/status",
            },
        }), 202

    except Exception as exc:
        logger.error(f"[Pipeline] Error starting pipeline: {traceback.format_exc()}")
        return jsonify({
            "success": False,
            "error": str(exc),
            "traceback": traceback.format_exc(),
        }), 500


@pipeline_bp.route("/<pipeline_id>/status", methods=["GET"])
def get_pipeline_status(pipeline_id: str):
    """
    Return the current status of a pipeline at any point in time.

    The worker thread owns all status transitions. This endpoint is read-only
    and simply reflects the persisted state plus live runner enrichment.

    Response:
        {
            "success": true,
            "data": {
                "pipeline_id":               "proj_xxxx",
                "project_id":                "proj_xxxx",
                "simulation_id":             "sim_xxxx",
                "pipeline_status":           "running|simulation_running|completed|failed",
                "current_stage":             "simulation_run",
                "progress":                  82,
                "stages_status": {
                    "ontology_generate":  "completed",
                    "graph_build":        "completed",
                    "simulation_create":  "completed",
                    "simulation_prepare": "completed",
                    "simulation_run":     "running",
                    "report_generate":    "pending"
                },
                "graph_build_task_id":        "task_xxxx",
                "graph_build_task":           { ...task detail... },
                "prepare_task_id":            "task_xxxx",
                "prepare_task":               { ...task detail... },
                "simulation_status":          "running",
                "runner_status":              "running",
                "current_round":               5,
                "total_rounds":                50,
                "simulation_progress_percent": 10.0,
                "report_id":                  "report_xxxx",
                "report_status":              "generating|completed|failed",
                "report_completed_at":        "...",
                "report_error":               null,
                "error":                      null,
                "created_at":                 "...",
                "updated_at":                 "..."
            }
        }
    """
    try:
        state = _load_pipeline(pipeline_id)
        if not state:
            return jsonify({
                "success": False,
                "error": f"Pipeline '{pipeline_id}' no encontrado",
            }), 404

        task_manager = TaskManager()
        result       = dict(state)

        # Enrich with graph-build task detail
        if state.get("graph_build_task_id"):
            task = task_manager.get_task(state["graph_build_task_id"])
            result["graph_build_task"] = task.to_dict() if task else None
        else:
            result["graph_build_task"] = None

        # Enrich with preparation task detail
        if state.get("prepare_task_id"):
            task = task_manager.get_task(state["prepare_task_id"])
            result["prepare_task"] = task.to_dict() if task else None
        else:
            result["prepare_task"] = None

        # Enrich with live simulation / runner state
        simulation_id = state.get("simulation_id")
        if simulation_id:
            sim_manager = SimulationManager()
            sim_state   = sim_manager.get_simulation(simulation_id)

            result["simulation_status"] = sim_state.status.value if sim_state else None
            if sim_state:
                result["entities_count"] = sim_state.entities_count
                result["profiles_count"] = sim_state.profiles_count

            run_state = SimulationRunner.get_run_state(simulation_id)
            if run_state:
                result["runner_status"] = run_state.runner_status.value
                result["current_round"] = run_state.current_round
                result["total_rounds"]  = run_state.total_rounds
                result["simulation_progress_percent"] = (
                    round(run_state.current_round / run_state.total_rounds * 100, 1)
                    if run_state.total_rounds > 0 else 0
                )
            else:
                result["runner_status"] = "idle"
                result["current_round"] = 0
                result["total_rounds"]  = 0
                result["simulation_progress_percent"] = 0

        else:
            # Simulation has not been created yet
            result["simulation_status"]           = None
            result["runner_status"]               = None
            result["current_round"]               = 0
            result["total_rounds"]                = 0
            result["simulation_progress_percent"] = 0

        # Enrich with report state
        report_id = state.get("report_id")
        if report_id:
            report = ReportManager.get_report(report_id)
            if report:
                result["report_id"]           = report_id
                result["report_status"]       = report.status.value
                result["report_completed_at"] = report.completed_at
                result["report_error"]        = report.error
            else:
                # Report object not yet written to disk (generation just started)
                result["report_id"]     = report_id
                result["report_status"] = "generating"
        else:
            result["report_id"]     = None
            result["report_status"] = None

        return jsonify({"success": True, "data": result})

    except Exception as exc:
        logger.error(f"[Pipeline] Error in /status: {traceback.format_exc()}")
        return jsonify({
            "success": False,
            "error": str(exc),
            "traceback": traceback.format_exc(),
        }), 500


@pipeline_bp.route("/list", methods=["GET"])
def list_pipelines():
    """
    List all persisted pipelines ordered by creation date (most recent first).

    Query params:
        limit — Maximum number of results to return (default: 20).
    """
    try:
        limit = request.args.get("limit", 20, type=int)

        uploads_projects = os.path.join(
            os.path.dirname(__file__), "../../uploads/projects"
        )
        if not os.path.exists(uploads_projects):
            return jsonify({"success": True, "data": [], "count": 0})

        pipelines = []
        for folder in os.listdir(uploads_projects):
            pipeline_file = os.path.join(uploads_projects, folder, "pipeline.json")
            if not os.path.exists(pipeline_file):
                continue
            try:
                with open(pipeline_file, "r", encoding="utf-8") as f:
                    p = json.load(f)
                pipelines.append(p)
            except Exception:
                continue

        pipelines.sort(key=lambda x: x.get("created_at", ""), reverse=True)
        pipelines = pipelines[:limit]

        return jsonify({
            "success": True,
            "data": pipelines,
            "count": len(pipelines),
        })

    except Exception as exc:
        logger.error(f"[Pipeline] Error in /list: {traceback.format_exc()}")
        return jsonify({"success": False, "error": str(exc)}), 500
