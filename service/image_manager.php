<?php
namespace mundophpbb\membermedals\service;

class image_manager
{
    protected string $phpbb_root_path;
    protected string $upload_dir = 'files/membermedals/';

    public function __construct(string $phpbb_root_path)
    {
        $this->phpbb_root_path = rtrim($phpbb_root_path, '/\\') . '/';
    }

    public function ensure_upload_dir(): bool
    {
        $target_dir = $this->phpbb_root_path . $this->upload_dir;

        if (is_dir($target_dir)) {
            return true;
        }

        if (@mkdir($target_dir, 0775, true) || is_dir($target_dir)) {
            return true;
        }

        return false;
    }

    public function normalize_storage_path(string $image_path): string
    {
        $image_path = trim(str_replace('\\', '/', $image_path));
        if ($image_path === '') {
            return '';
        }

        if (preg_match('#^(https?:)?//#i', $image_path)) {
            return $image_path;
        }

        if (preg_match('#(?:^|/)(files/membermedals/[^?\s]+)$#i', $image_path, $matches)) {
            return $matches[1];
        }

        if (preg_match('#(?:^|/)(membermedals/[^?\s]+)$#i', $image_path, $matches)) {
            return 'files/' . ltrim($matches[1], '/');
        }

        if (strpos($image_path, '/') === false) {
            return $this->upload_dir . $image_path;
        }

        return ltrim($image_path, '/');
    }

    public function image_exists(string $image_path): bool
    {
        $relative = $this->normalize_internal_path($image_path);
        if ($relative === '') {
            return false;
        }

        return is_file($this->phpbb_root_path . $relative);
    }

    public function upload_medal_image(array $file, string $current_image = '', string $medal_name = ''): array
    {
        if (empty($file) || !isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
            return [
                'success' => true,
                'uploaded' => false,
                'path' => $this->normalize_storage_path($current_image),
            ];
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'message' => 'ACP_MEMBERMEDALS_IMAGE_UPLOAD_ERROR',
            ];
        }

        $tmp_name = (string) ($file['tmp_name'] ?? '');
        if ($tmp_name === '' || !is_uploaded_file($tmp_name) || !is_readable($tmp_name)) {
            return [
                'success' => false,
                'message' => 'ACP_MEMBERMEDALS_IMAGE_UPLOAD_ERROR',
            ];
        }

        if ((int) ($file['size'] ?? 0) > 1048576) {
            return [
                'success' => false,
                'message' => 'ACP_MEMBERMEDALS_IMAGE_TOO_LARGE',
            ];
        }

        $image_info = @getimagesize($tmp_name);
        $mime = (string) ($image_info['mime'] ?? '');
        $allowed_mimes = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        if (!isset($allowed_mimes[$mime])) {
            return [
                'success' => false,
                'message' => 'ACP_MEMBERMEDALS_IMAGE_INVALID_TYPE',
            ];
        }

        if (!$this->ensure_upload_dir()) {
            return [
                'success' => false,
                'message' => 'ACP_MEMBERMEDALS_IMAGE_DIR_ERROR',
            ];
        }

        $target_dir = $this->phpbb_root_path . $this->upload_dir;
        $extension = $allowed_mimes[$mime];
        $slug = preg_replace('#[^a-z0-9]+#i', '_', strtolower($medal_name));
        $slug = trim((string) $slug, '_');
        if ($slug === '') {
            $slug = 'medal';
        }

        $hash = substr(sha1_file($tmp_name) ?: sha1((string) microtime(true)), 0, 12);
        $filename = $slug . '_' . date('Ymd_His') . '_' . $hash . '.' . $extension;
        $target_file = $target_dir . $filename;
        $contents = @file_get_contents($tmp_name);
        if ($contents === false || @file_put_contents($target_file, $contents, LOCK_EX) === false) {
            return [
                'success' => false,
                'message' => 'ACP_MEMBERMEDALS_IMAGE_MOVE_ERROR',
            ];
        }

        @chmod($target_file, 0644);

        $normalized_current = $this->normalize_storage_path($current_image);
        $normalized_new = $this->upload_dir . $filename;
        if ($normalized_current !== '' && $normalized_current !== $normalized_new) {
            $this->delete_internal_image($normalized_current);
        }

        return [
            'success' => true,
            'uploaded' => true,
            'path' => $normalized_new,
        ];
    }



    public function is_external_path(string $image_path): bool
    {
        $image_path = trim(str_replace('\\', '/', $image_path));
        return $image_path !== '' && (bool) preg_match('#^(https?:)?//#i', $image_path);
    }

    public function get_internal_full_path(string $image_path): string
    {
        $relative = $this->normalize_internal_path($image_path);
        if ($relative === '') {
            return '';
        }

        return $this->phpbb_root_path . $relative;
    }

    public function guess_mime_type(string $full_path): string
    {
        if ($full_path === '' || !is_file($full_path)) {
            return 'application/octet-stream';
        }

        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string) @finfo_file($finfo, $full_path);
                @finfo_close($finfo);
                if ($mime !== '') {
                    return $mime;
                }
            }
        }

        $ext = strtolower(pathinfo($full_path, PATHINFO_EXTENSION));
        $map = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ];

        return $map[$ext] ?? 'application/octet-stream';
    }

    public function delete_internal_image(string $image_path): void
    {
        $relative = $this->normalize_internal_path($image_path);
        if ($relative === '') {
            return;
        }

        $full_path = $this->phpbb_root_path . $relative;
        if (is_file($full_path)) {
            @unlink($full_path);
        }
    }

    protected function normalize_internal_path(string $image_path): string
    {
        $image_path = $this->normalize_storage_path($image_path);
        if ($image_path === '') {
            return '';
        }

        if (preg_match('#^(https?:)?//#i', $image_path)) {
            return '';
        }

        $prefix = $this->upload_dir;
        if (str_starts_with($image_path, $prefix)) {
            return $image_path;
        }

        if (str_starts_with($image_path, 'membermedals/')) {
            return 'files/' . ltrim($image_path, '/');
        }

        if (strpos($image_path, '/') === false) {
            return $prefix . $image_path;
        }

        return '';
    }
}
