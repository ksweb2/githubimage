<?php
/**
 * GitHub图床插件辅助类 - 优化版本
 * 
 * @package GitHubImageUpload
 * @author Your Name
 * @version 2.0.0
 */

class GitHubImageUpload_Helper
{
    /**
     * 记录日志
     */
    public static function log($message, $level = 'INFO')
    {
        $logFile = __DIR__ . '/logs/' . date('Y-m-d') . '.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }

    /**
     * 验证GitHub配置
     */
    public static function validateConfig($options)
    {
        $errors = array();
        
        if (empty($options->githubToken)) {
            $errors[] = 'GitHub Token不能为空';
        }
        
        if (empty($options->githubUser)) {
            $errors[] = 'GitHub用户名不能为空';
        }
        
        if (empty($options->githubRepo)) {
            $errors[] = 'GitHub仓库名不能为空';
        }
        
        if (!empty($options->githubToken) && !self::validateGitHubToken($options->githubToken)) {
            $errors[] = 'GitHub Token格式不正确';
        }
        
        return $errors;
    }

    /**
     * 验证GitHub Token格式
     */
    private static function validateGitHubToken($token)
    {
        // GitHub Personal Access Token格式验证 - 支持多种格式
        // 新格式: ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx (40字符)
        // 旧格式: 直接40个字符的十六进制字符串
        if (preg_match('/^gh[opurs]_[a-zA-Z0-9]{36}$/', $token)) {
            return true; // 新格式
        }
        
        if (preg_match('/^[a-f0-9]{40}$/', $token)) {
            return true; // 旧格式
        }
        
        // 如果都不匹配，记录Token信息用于调试
        self::log("Token格式验证失败，Token长度: " . strlen($token));
        self::log("Token前缀: " . substr($token, 0, 10) . "...");
        
        return false;
    }

    /**
     * 验证GitHub Token权限
     */
    public static function validateTokenPermissions($options)
    {
        try {
            $apiUrl = self::getApiUrl($options, "user");
            $result = self::sendGitHubRequest($apiUrl, null, $options->githubToken, 'GET', $options);
            
            if (!$result || !isset($result['login'])) {
                return array('valid' => false, 'message' => 'Token无效或已过期');
            }
            
            $username = $result['login'];
            self::log("Token验证成功，用户: {$username}");
            
            return array(
                'valid' => true, 
                'message' => 'Token权限验证通过',
                'user' => $username
            );
            
        } catch (Exception $e) {
            return array('valid' => false, 'message' => 'Token验证失败: ' . $e->getMessage());
        }
    }

    /**
     * 检查仓库访问权限
     */
    public static function checkRepositoryAccess($options)
    {
        try {
            $apiUrl = self::getApiUrl($options, "repos/{$options->githubUser}/{$options->githubRepo}");
            $result = self::sendGitHubRequest($apiUrl, null, $options->githubToken, 'GET', $options);
            
            if ($result && isset($result['full_name'])) {
                $defaultBranch = isset($result['default_branch']) ? $result['default_branch'] : 'main';
                $isPrivate = isset($result['private']) ? $result['private'] : false;
                
                self::log("仓库访问成功: {$result['full_name']}, 默认分支: {$defaultBranch}");
                
                return array(
                    'accessible' => true,
                    'default_branch' => $defaultBranch,
                    'is_private' => $isPrivate,
                    'full_name' => $result['full_name']
                );
            } else {
                return array('accessible' => false, 'message' => '无法访问仓库');
            }
            
        } catch (Exception $e) {
            return array('accessible' => false, 'message' => '仓库访问失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取API URL（支持镜像）
     */
    public static function getApiUrl($options, $endpoint)
    {
        $baseUrl = "https://api.github.com/";
        
        // 如果启用了API镜像
        if ($options->useApiMirror && !empty($options->apiMirror)) {
            $baseUrl = rtrim($options->apiMirror, '/') . '/';
            self::log("使用API镜像: {$baseUrl}");
        }
        
        return $baseUrl . ltrim($endpoint, '/');
    }

    /**
     * 发送GitHub API请求 - 优化版本
     */
    public static function sendGitHubRequest($url, $data = null, $token, $method = 'POST', $options = null)
    {
        $headers = array(
            'Authorization: token ' . $token,
            'User-Agent: Typecho-GitHub-Image-Upload-Plugin/2.0.0'
        );

        if ($data && ($method === 'POST' || $method === 'PUT')) {
            $headers[] = 'Content-Type: application/json';
        }

        self::log("发送GitHub API请求: {$method} {$url}");

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        if ($method === 'POST' && $data) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PUT' && $data) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'DELETE' && $data) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $startTime = microtime(true);
        $response = curl_exec($ch);
        $endTime = microtime(true);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $requestTime = round(($endTime - $startTime) * 1000, 2);
        curl_close($ch);

        self::log("API响应: HTTP {$httpCode}, 耗时 {$requestTime}ms");
        
        if ($error) {
            self::log("cURL错误: {$error}", 'ERROR');
            throw new Exception('cURL错误: ' . $error);
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $responseData = json_decode($response, true);
            if (isset($responseData['content']['download_url'])) {
                self::log("上传成功，文件URL: " . $responseData['content']['download_url']);
            }
            return $responseData;
        } else {
            $errorData = json_decode($response, true);
            $errorMessage = isset($errorData['message']) ? $errorData['message'] : '未知错误';
            self::log("API请求失败: {$errorMessage}", 'ERROR');
            throw new Exception("GitHub API请求失败 (HTTP {$httpCode}): {$errorMessage}");
        }
    }

    /**
     * 确保目录路径存在 - 优化版本
     */
    public static function ensureDirectoryExists($options, $path)
    {
        try {
            // 检查路径是否存在
            $checkUrl = self::getApiUrl($options, "repos/{$options->githubUser}/{$options->githubRepo}/contents/{$path}");
            $result = self::sendGitHubRequest($checkUrl, null, $options->githubToken, 'GET', $options);
            
            if ($result !== false) {
                self::log("路径 {$path} 已存在");
                return true;
            }
            
        } catch (Exception $e) {
            // 如果路径不存在，尝试创建
            self::log("路径 {$path} 不存在，尝试创建");
            
            // 递归创建目录结构
            $pathParts = explode('/', $path);
            $currentPath = '';
            
            foreach ($pathParts as $part) {
                if (empty($part)) continue;
                
                $currentPath .= ($currentPath ? '/' : '') . $part;
                
                try {
                    // 检查当前路径是否存在
                    $checkUrl = self::getApiUrl($options, "repos/{$options->githubUser}/{$options->githubRepo}/contents/{$currentPath}");
                    $checkResult = self::sendGitHubRequest($checkUrl, null, $options->githubToken, 'GET', $options);
                    
                    if ($checkResult !== false) {
                        self::log("路径 {$currentPath} 已存在");
                        continue;
                    }
                    
                } catch (Exception $checkError) {
                    // 路径不存在，创建它
                    self::log("创建目录: {$currentPath}");
                    
                    try {
                        // 创建目录（通过创建一个隐藏文件）
                        $createUrl = self::getApiUrl($options, "repos/{$options->githubUser}/{$options->githubRepo}/contents/{$currentPath}/.gitkeep");
                        $data = array(
                            'message' => "Create directory: {$currentPath}",
                            'content' => base64_encode(''),
                            'branch' => $options->githubBranch
                        );
                        
                        $createResult = self::sendGitHubRequest($createUrl, $data, $options->githubToken, 'PUT', $options);
                        self::log("目录 {$currentPath} 创建成功");
                        
                    } catch (Exception $createError) {
                        self::log("创建目录 {$currentPath} 失败: " . $createError->getMessage(), 'ERROR');
                        return false;
                    }
                }
            }
            
            return true;
        }
        
        return false;
    }

    /**
     * 生成GitHub镜像URL
     */
    public static function generateMirrorUrl($originalUrl, $mirrorUrl, $githubUser, $githubRepo, $githubBranch, $mirrorMode = 'gh-proxy')
    {
        if (empty($mirrorUrl)) {
            return $originalUrl;
        }

        // 清理镜像URL
        $mirrorUrl = rtrim($mirrorUrl, '/');
        
        self::log("生成镜像URL - 原始URL: {$originalUrl}");
        self::log("生成镜像URL - 镜像URL: {$mirrorUrl}");
        self::log("生成镜像URL - 镜像模式: {$mirrorMode}");
        
        if ($mirrorMode === 'gh-proxy') {
            // gh-proxy格式: https://hk.gh-proxy.com/https://raw.githubusercontent.com/...
            $result = $mirrorUrl . '/' . $originalUrl;
            self::log("gh-proxy格式结果: {$result}");
            return $result;
        } else {
            // 传统镜像格式: https://raw.kkgithub.com/user/repo/branch/...
            // 将 https://raw.githubusercontent.com/user/repo/branch/ 替换为 https://raw.kkgithub.com/user/repo/branch/
            $pattern = "https://raw.githubusercontent.com/{$githubUser}/{$githubRepo}/{$githubBranch}/";
            $replacement = "https://raw.kkgithub.com/{$githubUser}/{$githubRepo}/{$githubBranch}/";
            $result = str_replace($pattern, $replacement, $originalUrl);
            self::log("传统镜像格式结果: {$result}");
            return $result;
        }
    }

    /**
     * 获取支持的文件类型
     */
    public static function getSupportedFileTypes()
    {
        return array(
            'image/jpeg' => 'JPEG图片',
            'image/jpg' => 'JPG图片', 
            'image/png' => 'PNG图片',
            'image/gif' => 'GIF图片',
            'image/webp' => 'WebP图片'
        );
    }

    /**
     * 检查文件类型是否支持
     */
    public static function isSupportedFileType($fileType)
    {
        $supportedTypes = array_keys(self::getSupportedFileTypes());
        return in_array($fileType, $supportedTypes);
    }

    /**
     * 格式化文件大小
     */
    public static function formatFileSize($bytes)
    {
        $units = array('B', 'KB', 'MB', 'GB');
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * 压缩图片 - 优化版本
     */
    public static function compressImage($filePath, $quality = 85, $maxWidth = 1920, $maxHeight = 1080)
    {
        if (!extension_loaded('gd')) {
            self::log("GD扩展未加载，跳过图片压缩", 'WARNING');
            return false;
        }

        $imageInfo = getimagesize($filePath);
        if (!$imageInfo) {
            self::log("无法获取图片信息: {$filePath}", 'ERROR');
            return false;
        }

        $originalWidth = $imageInfo[0];
        $originalHeight = $imageInfo[1];
        $mimeType = $imageInfo['mime'];

        self::log("原始图片尺寸: {$originalWidth}x{$originalHeight}, 类型: {$mimeType}");

        // 检查是否需要压缩
        $needsResize = ($originalWidth > $maxWidth || $originalHeight > $maxHeight);

        if (!$needsResize) {
            self::log("图片无需压缩");
            return false;
        }

        // 创建图片资源
        $image = null;
        switch ($mimeType) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($filePath);
                break;
            case 'image/png':
                $image = imagecreatefrompng($filePath);
                break;
            case 'image/gif':
                $image = imagecreatefromgif($filePath);
                break;
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $image = imagecreatefromwebp($filePath);
                }
                break;
            default:
                self::log("不支持的图片类型: {$mimeType}", 'ERROR');
                return false;
        }

        if (!$image) {
            self::log("无法创建图片资源", 'ERROR');
            return false;
        }

        // 计算新尺寸
            $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
            $newWidth = intval($originalWidth * $ratio);
            $newHeight = intval($originalHeight * $ratio);

        // 创建新图片
        $newImage = imagecreatetruecolor($newWidth, $newHeight);
        
        // 处理PNG透明背景
        if ($mimeType === 'image/png') {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
            imagefill($newImage, 0, 0, $transparent);
        }

        // 调整图片大小
            imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

        // 保存压缩后的图片
        $result = false;
        switch ($mimeType) {
            case 'image/jpeg':
                $result = imagejpeg($newImage, $filePath, $quality);
                break;
            case 'image/png':
                // PNG压缩级别 (0-9, 9为最高压缩)
                $pngQuality = 9 - intval($quality / 10);
                $result = imagepng($newImage, $filePath, $pngQuality);
                break;
            case 'image/gif':
                $result = imagegif($newImage, $filePath);
                break;
            case 'image/webp':
                if (function_exists('imagewebp')) {
                    $result = imagewebp($newImage, $filePath, $quality);
                }
                break;
        }

        // 清理资源
        imagedestroy($image);
        imagedestroy($newImage);

        if ($result) {
            self::log("图片压缩完成: {$newWidth}x{$newHeight}, 质量: {$quality}%");
            return true;
        } else {
            self::log("图片压缩失败", 'ERROR');
            return false;
        }
    }

    /**
     * 测试GitHub连接
     */
    public static function testConnection($options)
    {
        try {
            // 首先验证Token权限
            $tokenValidation = self::validateTokenPermissions($options);
            if (!$tokenValidation['valid']) {
                return array('success' => false, 'message' => $tokenValidation['message']);
            }
            
            // 检查仓库访问权限
            $repoCheck = self::checkRepositoryAccess($options);
            if (!$repoCheck['accessible']) {
                return array('success' => false, 'message' => $repoCheck['message']);
            }
            
            // 检查分支配置
            $defaultBranch = $repoCheck['default_branch'];
            if ($options->githubBranch !== $defaultBranch) {
                return array(
                    'success' => false, 
                    'message' => "配置的分支 '{$options->githubBranch}' 与仓库默认分支 '{$defaultBranch}' 不匹配，建议修改为 '{$defaultBranch}'"
                );
        }

        return array(
                'success' => true, 
                'message' => "连接成功！用户: {$tokenValidation['user']}, 仓库: {$repoCheck['full_name']}, 默认分支: {$defaultBranch}"
            );
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * 清理日志文件
     */
    public static function cleanLogs($days = 30)
    {
        $logDir = __DIR__ . '/logs/';
        if (!is_dir($logDir)) {
            return;
        }

        $files = glob($logDir . '*.log');
        $cutoffTime = time() - ($days * 24 * 60 * 60);

        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                unlink($file);
            }
        }
    }
}