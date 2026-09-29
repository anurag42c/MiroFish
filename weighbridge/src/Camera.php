<?php
declare(strict_types=1);

/** Optional evidence photo from an IP camera snapshot URL (JPEG). Never blocks or fails a weighment. */
final class Camera
{
    public static function dir(): string
    {
        $d = WB_DATA . '/snapshots';
        if (!is_dir($d)) { @mkdir($d, 0770, true); }
        return $d;
    }

    /** @return string|null file name saved under data/snapshots */
    public static function snapshot(string $ticket, string $tag): ?string
    {
        $url = trim((string)Settings::get('cam_url'));
        if ($url === '' || !preg_match('#^https?://#i', $url) || !function_exists('curl_init')) { return null; }
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_HTTPAUTH => CURLAUTH_ANY, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false]);
            if (Settings::get('cam_user') !== '') { curl_setopt($ch, CURLOPT_USERPWD, Settings::get('cam_user') . ':' . Settings::get('cam_pass')); }
            $body = curl_exec($ch);
            $ok = $body !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && str_starts_with((string)$body, "\xFF\xD8");
            curl_close($ch);
            if (!$ok) { return null; }
            $name = preg_replace('/[^A-Za-z0-9_-]/', '', $ticket) . "_$tag.jpg";
            file_put_contents(self::dir() . '/' . $name, $body);
            return $name;
        } catch (Throwable) { return null; }
    }
}
