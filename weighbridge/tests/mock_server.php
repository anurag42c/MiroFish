<?php
// Router for `php -S`: fake IP camera + fake ANPR services (Plate Recognizer, CodeProject.AI formats) for tests.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');

function plate_in_upload(): ?string {
    $f = $_FILES['upload']['tmp_name'] ?? null;
    if (!$f) { return null; }
    return preg_match('/PLATE:([A-Z0-9]+)/', (string)file_get_contents($f), $m) ? $m[1] : null;
}

switch ($path) {
    case '/snap.jpg':                               // camera: JPEG that "contains" the plate given in ?plate=
        header('Content-Type: image/jpeg');
        echo "\xFF\xD8\xFF\xE0", isset($_GET['plate']) ? 'PLATE:' . preg_replace('/[^A-Z0-9]/', '', strtoupper($_GET['plate'])) : 'EMPTYROAD', str_repeat("\x00", 64), "\xFF\xD9";
        break;
    case '/notjpeg.jpg': echo 'hello'; break;
    case '/pr':                                     // Plate Recognizer format
        if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Token testkey') { http_response_code(403); echo '{"detail":"Invalid token."}'; break; }
        $p = plate_in_upload();
        echo json_encode(['results' => $p ? [['plate' => strtolower($p), 'score' => 0.93, 'dscore' => 0.8], ['plate' => 'zz99', 'score' => 0.2]] : []]);
        break;
    case '/cp':                                     // CodeProject.AI format
        $p = plate_in_upload();
        echo json_encode(['success' => true, 'predictions' => $p ? [['label' => "Plate: $p", 'plate' => $p, 'confidence' => 0.88]] : [], 'code' => 200]);
        break;
    case '/boom': http_response_code(500); echo 'oops'; break;
    default: http_response_code(404);
}
