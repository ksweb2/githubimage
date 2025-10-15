<?php
/**
 * GitHub图床插件
 * 
 * @package GitHubImageUpload
 * @author ksweb2
 * @version 3.0.0
 * @link https://github.com/ksweb2/githubimage
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class GitHubImageUpload_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件
     */
    public static function activate()
    {
        // 注册上传钩子
        Typecho_Plugin::factory('Widget_Upload')->uploadHandle = array('GitHubImageUpload_Plugin', 'uploadHandle');
        Typecho_Plugin::factory('Widget_Upload')->attachmentHandle = array('GitHubImageUpload_Plugin', 'attachmentHandle');
        Typecho_Plugin::factory('Widget_Upload')->deleteHandle = array('GitHubImageUpload_Plugin', 'deleteHandle');
        
        // 注册内容过滤器
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->contentFilter = array('GitHubImageUpload_Plugin', 'contentFilter');
        Typecho_Plugin::factory('Widget_Contents_Page_Edit')->contentFilter = array('GitHubImageUpload_Plugin', 'contentFilter');
		
		// 覆盖后台编辑器插入行为，粘贴/上传后将 /usr/uploads 路径改写为 GitHub/mirror URL
		Typecho_Plugin::factory('admin/write-js.php')->write = array('GitHubImageUpload_Plugin', 'editorInject');
    }

    /**
     * 停用插件
     */
    public static function deactivate()
    {
        // 清理钩子
    }

    /**
     * 配置面板
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $githubToken = new Typecho_Widget_Helper_Form_Element_Text('githubToken', NULL, '', _t('GitHub Token'), _t('GitHub Personal Access Token'));
        $form->addInput($githubToken);

        $githubUser = new Typecho_Widget_Helper_Form_Element_Text('githubUser', NULL, '', _t('GitHub用户名'), _t('GitHub用户名'));
        $form->addInput($githubUser);

        $githubRepo = new Typecho_Widget_Helper_Form_Element_Text('githubRepo', NULL, '', _t('GitHub仓库名'), _t('GitHub仓库名'));
        $form->addInput($githubRepo);

        $githubBranch = new Typecho_Widget_Helper_Form_Element_Text('githubBranch', NULL, 'main', _t('GitHub分支'), _t('用于读取与删除内容的分支，默认 main'));
        $form->addInput($githubBranch);

        $useMirror = new Typecho_Widget_Helper_Form_Element_Radio(
            'useMirror',
            array('0' => _t('不使用'), '1' => _t('使用')), 
            '0',
            _t('使用镜像'),
            _t('是否将图片直链改写为镜像/代理以加速访问（仅图片生效）')
        );
        $form->addInput($useMirror);

        $mirrorMode = new Typecho_Widget_Helper_Form_Element_Radio(
            'mirrorMode',
            array('traditional' => _t('传统 raw 镜像'), 'gh-proxy' => _t('gh-proxy 代理')),
            'traditional',
            _t('镜像模式'),
            _t('显式选择镜像加速模式，避免自动识别误判')
        );
        $form->addInput($mirrorMode);

        $mirrorUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'mirrorUrl',
            NULL,
            '',
            _t('内容镜像地址'),
            _t('根据镜像模式填写：\n- 传统：如 https://raw.kkgithub.com\n- gh-proxy：如 https://hk.gh-proxy.com\n留空则使用 https://raw.githubusercontent.com/')
        );
        $form->addInput($mirrorUrl);

        $apiMirrorUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'apiMirrorUrl',
            NULL,
            '',
            _t('API 镜像地址'),
            _t('用于上传走 GitHub API 的加速（仅支持传统镜像），如：https://api.kkgithub.com/\n仅当包含“api.”时才会启用替换，避免前缀代理导致双重 URL')
        );
        $form->addInput($apiMirrorUrl);

        // 图片优化开关（智能优化，无需其他参数）
        $enableOpt = new Typecho_Widget_Helper_Form_Element_Radio(
            'enableOptimization',
            array('0' => _t('关闭'), '1' => _t('开启')),
            '1',
            _t('图片智能压缩'),
            _t('对 JPG/PNG/WebP 在上传前进行等比缩放与压缩；默认开启，可一键关闭')
        );
        $form->addInput($enableOpt);
    }

    /**
     * 个人配置面板
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
        // 个人配置暂时为空
    }

    /**
     * 记录日志到插件目录
     */
    private static function log($message)
    {
        $logDir = __DIR__ . '/log';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . '/github_upload_' . date('Y-m-d') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}" . PHP_EOL;
        
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
    /**
     * 处理文件上传
     */
    public static function uploadHandle($file)
    {
        // 启用错误报告
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        
        // 记录开始
        self::log("uploadHandle开始 - 文件: " . (isset($file['name']) ? $file['name'] : 'unknown'));
        
        // 基本验证
        if (empty($file['name']) || empty($file['tmp_name'])) {
            self::log("文件验证失败 - name: " . (isset($file['name']) ? $file['name'] : 'empty') . ", tmp_name: " . (isset($file['tmp_name']) ? $file['tmp_name'] : 'empty'));
            return false;
        }

        // 文件扩展名
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $imageExts = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg');

        try {
            // 非图片：走本地保存（等价于Typecho默认流程），避免返回false导致整体失败
            if (!in_array($ext, $imageExts)) {
                self::log("非图片类型，使用本地保存: " . $ext);
                $relativePathBase = defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : '/usr/uploads';
                $rootBase = defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__;
                $year = date('Y');
                $month = date('m');
                $dir = rtrim($rootBase, '/\\') . $relativePathBase . '/' . $year . '/' . $month;
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                $safeExt = preg_replace('/[^a-z0-9]+/i', '', $ext);
                $newName = sprintf('%u', crc32(uniqid())) . ($safeExt ? ('.' . $safeExt) : '');
                $targetPath = $dir . '/' . $newName;
                if (!@move_uploaded_file($file['tmp_name'], $targetPath)) {
                    self::log("本地保存失败: move_uploaded_file");
                    return false;
                }
                $size = isset($file['size']) ? $file['size'] : @filesize($targetPath);
                $mime = isset($file['type']) ? $file['type'] : (function_exists('mime_content_type') ? @mime_content_type($targetPath) : 'application/octet-stream');
                $result = array(
                    'name' => $file['name'],
                    'path' => $relativePathBase . '/' . $year . '/' . $month . '/' . $newName,
                    'size' => $size,
                    'type' => $safeExt,
                    'mime' => $mime
                );
                self::log("本地保存完成，返回: " . json_encode($result));
                return $result;
            }

            // 获取配置（图片上传到GitHub）
            $options = Typecho_Widget::widget('Widget_Options');
            $pluginOptions = $options->plugin('GitHubImageUpload');
            
            self::log("配置检查 - Token: " . (empty($pluginOptions->githubToken) ? 'empty' : 'set') . 
                     ", User: " . (empty($pluginOptions->githubUser) ? 'empty' : $pluginOptions->githubUser) . 
                     ", Repo: " . (empty($pluginOptions->githubRepo) ? 'empty' : $pluginOptions->githubRepo));
            
            if (empty($pluginOptions->githubToken) || empty($pluginOptions->githubUser) || empty($pluginOptions->githubRepo)) {
                self::log("配置不完整，上传失败");
                return false;
            }

            // 生成文件名
            $fileName = $file['name'];
            $newFileName = date('Y/m/d') . '/' . uniqid() . '.' . $ext;
            
            self::log("开始上传到GitHub - 路径: " . $newFileName);
            
            // 上传到GitHub（无需预创建目录）
            $result = self::uploadToGitHub($file, $newFileName, $pluginOptions);
            
            if ($result) {
                self::log("上传成功");
                
                // 生成最终访问URL（支持镜像策略）
                $finalUrl = self::buildRawUrl($pluginOptions->githubUser, $pluginOptions->githubRepo, $newFileName, $pluginOptions);
                
                self::log("生成最终URL: " . $finalUrl);
                
                // 返回Typecho期望的格式，直接使用GitHub URL作为path
                $result = array(
                    'name' => $fileName,
                    'path' => $finalUrl,  // 直接使用GitHub URL作为path
                    'size' => $file['size'],
                    'type' => $file['type'],
                    'mime' => $file['type']
                );
                
                self::log("返回数据: " . json_encode($result));
                return $result;
            } else {
                self::log("上传失败");
            }
            
            return false;
            
        } catch (Exception $e) {
            self::log("异常: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 附件URL处理器
     */
    public static function attachmentHandle($content)
    {
        try {
            self::log("=== attachmentHandle开始 ===");
            self::log("attachmentHandle被调用");
            self::log("attachmentHandle - content: " . json_encode($content));
            
            if (isset($content['attachment']) && isset($content['attachment']->path)) {
                $path = $content['attachment']->path;
                self::log("attachmentHandle - path: " . $path);
                
                // 已是完整URL：如为 raw.githubusercontent.com 且启用镜像，则改写为镜像；否则原样返回
                if (is_string($path) && (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0)) {
                    try {
                        if (strpos($path, 'https://raw.githubusercontent.com/') === 0) {
                            $options = Typecho_Widget::widget('Widget_Options');
                            $pluginOptions = $options->plugin('GitHubImageUpload');
                            $useMirror = !empty($pluginOptions->useMirror) && !empty($pluginOptions->mirrorUrl);
                            if ($useMirror) {
                                $branch = !empty($pluginOptions->githubBranch) ? $pluginOptions->githubBranch : 'main';
                                if (preg_match('#^https://raw\.githubusercontent\.com/([^/]+)/([^/]+)/' . preg_quote($branch, '#') . '/images/(.+)$#', $path, $m)) {
                                    $user = $m[1];
                                    $repo = $m[2];
                                    $rel = $m[3];
                                    $mirrored = self::buildRawUrl($user, $repo, $rel, $pluginOptions);
                                    self::log("attachmentHandle - 绝对URL镜像改写: " . $mirrored);
                                    return $mirrored;
                                }
                            }
                        }
                    } catch (Exception $e) {
                        self::log("attachmentHandle - 绝对URL镜像改写异常: " . $e->getMessage());
                    }
                    self::log("attachmentHandle - 已是绝对URL，直接返回");
                    return $path;
                }
                
                // 相对路径（本地上传）尝试映射到 GitHub/mirror，仅限图片
                if (preg_match('/^\d{4}\/\d{2}\/\d{2}\/[^\s]+\.(jpg|jpeg|png|gif|webp|bmp|svg)$/i', $path)) {
                    $options = Typecho_Widget::widget('Widget_Options');
                    $pluginOptions = $options->plugin('GitHubImageUpload');
                    $relative = $path;
                    $finalUrl = self::buildRawUrl($pluginOptions->githubUser, $pluginOptions->githubRepo, $relative, $pluginOptions);
                    self::log("attachmentHandle - 生成GitHub URL: " . $finalUrl);
                    return $finalUrl;
                }
                
                // 默认：返回Typecho原逻辑的URL
                $base = defined('__TYPECHO_UPLOAD_URL__') ? __TYPECHO_UPLOAD_URL__ : Typecho_Widget::widget('Widget_Options')->siteUrl;
                $defaultUrl = \Typecho\Common::url($path, $base);
                self::log("attachmentHandle - 默认URL: " . $defaultUrl);
                return $defaultUrl;
            }
            
            // 非预期结构，返回站点根，保证类型为字符串避免致命错误
            $fallback = Typecho_Widget::widget('Widget_Options')->siteUrl;
            self::log("attachmentHandle - 未检测到attachment，返回站点URL作为回退");
            return $fallback;
        } catch (Exception $e) {
            self::log("attachmentHandle异常: " . $e->getMessage());
            // 异常时也返回站点URL，避免返回null触发类型错误
            return Typecho_Widget::widget('Widget_Options')->siteUrl;
        }
    }

    /**
     * 内容过滤器
     */
    public static function contentFilter($content)
    {
        try {
            if (!is_string($content)) {
                return $content;
            }
            
            self::log("contentFilter被调用，内容长度: " . strlen($content));
            
            // 检查是否包含本地URL
            if (strpos($content, 'https://boke.xn--um0a97l.top/') !== false) {
                $options = Typecho_Widget::widget('Widget_Options');
                $pluginOptions = $options->plugin('GitHubImageUpload');
                $build = function($rel) use ($pluginOptions){ return GitHubImageUpload_Plugin::buildRawUrl($pluginOptions->githubUser, $pluginOptions->githubRepo, $rel, $pluginOptions); };
                
                // 替换本地URL为GitHub URL
                $newContent = preg_replace_callback(
                    '/https:\\/\\/boke\\.xn--um0a97l\\.top\\\/(\\d{4}\\/\\d{2}\\/\\d{2}\\\/[^\\s]+\\.(?:jpg|jpeg|png|gif|webp|bmp|svg))(?:[#?][^\\s\"]*)?/i',
                    function($m) use ($build){ return $build($m[1]); },
                    $content
                );
                
                if ($newContent !== $content) {
                    self::log("内容URL替换成功");
                    return $newContent;
                }
            }
        } catch (Exception $e) {
            self::log("contentFilter异常: " . $e->getMessage());
        }
        
        return $content;
    }

    /**
     * 构建原始内容访问URL，支持镜像（traditional 与 gh-proxy）
     */
    private static function buildRawUrl($user, $repo, $relativePath, $pluginOptions)
    {
        $relative = ltrim($relativePath, '/');
        $branch = !empty($pluginOptions->githubBranch) ? $pluginOptions->githubBranch : 'main';
        $raw = "https://raw.githubusercontent.com/{$user}/{$repo}/{$branch}/images/{$relative}";
        $useMirror = !empty($pluginOptions->useMirror) && !empty($pluginOptions->mirrorUrl);
        if (!$useMirror) {
            return $raw;
        }
        $mirror = rtrim($pluginOptions->mirrorUrl, '/');
        $mode = isset($pluginOptions->mirrorMode) ? (string)$pluginOptions->mirrorMode : 'traditional';
        if ($mode === 'gh-proxy') {
            return $mirror . '/' . preg_replace('#^https?://#', '', $raw);
        }
        // traditional
        return str_replace('https://raw.githubusercontent.com', $mirror, $raw);
    }

    /**
     * 注入编辑器JS，统一改写插入到编辑器的URL
     */
    public static function editorInject()
    {
        echo "<script>\n";
        echo "(function(){\n";
        echo "  function buildGithubUrl(rel){\n";
        echo "    rel = (rel||'').replace(/^\\/*/, '');\n";
        echo "    if(!/^\\d{4}\\/\\d{2}\\/\\d{2}\\//.test(rel)){ return null; }\n";
        echo "    if(!/\\.(png|jpe?g|gif|webp|bmp|svg)(?:[#?].*)?$/i.test(rel)){ return null; }\n";
        echo "    var user='" . addslashes(Typecho_Widget::widget('Widget_Options')->plugin('GitHubImageUpload')->githubUser) . "';\n";
        echo "    var repo='" . addslashes(Typecho_Widget::widget('Widget_Options')->plugin('GitHubImageUpload')->githubRepo) . "';\n";
        echo "    var branch='" . addslashes(Typecho_Widget::widget('Widget_Options')->plugin('GitHubImageUpload')->githubBranch ?: 'main') . "';\n";
        echo "    var useMirror=" . (Typecho_Widget::widget('Widget_Options')->plugin('GitHubImageUpload')->useMirror ? 'true' : 'false') . ";\n";
        echo "    var mirrorUrl='" . addslashes(rtrim(Typecho_Widget::widget('Widget_Options')->plugin('GitHubImageUpload')->mirrorUrl, '/')) . "';\n";
        echo "    var mirrorMode='" . addslashes(Typecho_Widget::widget('Widget_Options')->plugin('GitHubImageUpload')->mirrorMode ?: 'traditional') . "';\n";
        echo "    var raw='https://raw.githubusercontent.com/' + user + '/' + repo + '/' + branch + '/images/' + rel;\n";
        echo "    if(useMirror && mirrorUrl){\n";
        echo "      if(mirrorMode === 'gh-proxy'){ return mirrorUrl + '/' + raw.replace(/^https?:\\/\\//,''); }\n";
        echo "      return raw.replace('https://raw.githubusercontent.com', mirrorUrl);\n";
        echo "    }\n";
        echo "    return raw;\n";
        echo "  }\n";
        echo "  var nativeInsert = window.Typecho && Typecho.insertFileToEditor;\n";
        echo "  if(typeof nativeInsert === 'function'){\n";
        echo "    Typecho.insertFileToEditor = function(file, url, isImage){\n";
        echo "      try{\n";
        echo "        var u = String(url||'');\n";
        echo "        // 仅在图片文件时改写到 GitHub/mirror\n";
        echo "        var imgFlag = (isImage === true || isImage === '1' || isImage === 1);\n";
        echo "        var looksImage = /\\.(png|jpe?g|gif|webp|bmp|svg)(?:[#?].*)?$/i.test(u);\n";
        echo "        if(!(imgFlag || looksImage)){ return nativeInsert.call(this, file, url, isImage); }\n";
        echo "        var m = u.match(/(?:\\/usr\\/uploads\\/)(\\d{4}\\/\\d{2}\\/\\d{2}\\/[^\\)\\s]+)$/);\n";
        echo "        if(!m){\n";
        echo "          // 兼容站点完整域名前缀\n";
        echo "          var a = document.createElement('a'); a.href = u;\n";
        echo "          var p = a.pathname || '';\n";
        echo "          var mm = p.match(/\\/usr\\/uploads\\/(\\d{4}\\/\\d{2}\\/\\d{2}\\/[^\\)\\s]+)$/);\n";
        echo "          if(mm){ m = [u, mm[1]]; }\n";
        echo "        }\n";
        echo "        if(m && m[1]){\n";
        echo "          var gh = buildGithubUrl(m[1]);\n";
        echo "          if(gh){ url = gh; }\n";
        echo "        }\n";
        echo "      }catch(e){}\n";
        echo "      return nativeInsert.call(this, file, url, isImage);\n";
        echo "    };\n";
        echo "  }\n";
        echo "})();\n";
        echo "</script>\n";
    }

    /**
     * 删除附件时同步删除 GitHub 文件
     */
    public static function deleteHandle($content)
    {
        try {
            self::log("deleteHandle调用");
            if (!isset($content['attachment']) || !isset($content['attachment']->path)) {
                return false; // 走默认删除
            }
            $path = (string)$content['attachment']->path;

            // 仅处理本插件上传到 GitHub 的图片。非 http(s) 路径交给默认逻辑
            if (strpos($path, 'http://') !== 0 && strpos($path, 'https://') !== 0) {
                return false;
            }

            $options = Typecho_Widget::widget('Widget_Options');
            $pluginOptions = $options->plugin('GitHubImageUpload');
            if (empty($pluginOptions->githubToken) || empty($pluginOptions->githubUser) || empty($pluginOptions->githubRepo)) {
                self::log('删除中止：插件未配置完整 GitHub 信息');
                return false;
            }
            $branch = !empty($pluginOptions->githubBranch) ? $pluginOptions->githubBranch : 'main';

            // 从 raw 或镜像 URL 中提取相对路径 YYYY/MM/DD/xxx.ext
            $relative = self::extractRelativeFromUrl($path, $pluginOptions);
            if ($relative === null) {
                self::log('未能从URL提取相对路径，跳过GitHub删除: ' . $path);
                return false;
            }

            $apiBase = self::getApiBase($pluginOptions);
            $repo = urlencode($pluginOptions->githubRepo);
            $user = urlencode($pluginOptions->githubUser);
            $contentPath = 'images/' . ltrim($relative, '/');

            // 先获取文件 sha
            $infoUrl = $apiBase . "repos/{$user}/{$repo}/contents/" . rawurlencode($contentPath) . '?ref=' . rawurlencode($branch);
            self::log('获取文件信息: ' . $infoUrl);
            $resp = self::curlJson($infoUrl, 'GET', null, $pluginOptions->githubToken);
            if (!$resp || empty($resp['sha'])) {
                self::log('获取 sha 失败，可能文件已不存在于 GitHub');
                return true; // 视为已删除
            }
            $sha = $resp['sha'];

            // 调用 DELETE 删除
            $delUrl = $apiBase . "repos/{$user}/{$repo}/contents/" . rawurlencode($contentPath);
            $payload = array(
                'message' => 'Delete image via Typecho (sync)',
                'sha' => $sha,
                'branch' => $branch
            );
            self::log('删除 GitHub 文件: ' . $delUrl);
            $delResp = self::curlJson($delUrl, 'DELETE', $payload, $pluginOptions->githubToken, 30);
            if (isset($delResp['commit'])) {
                self::log('GitHub 删除成功: ' . $contentPath);
                return true;
            }
            self::log('GitHub 删除响应异常');
            return true; // 不阻塞本地流程
        } catch (Exception $e) {
            self::log('deleteHandle异常: ' . $e->getMessage());
            return false;
        }
    }

    private static function getApiBase($options)
    {
        $apiBase = 'https://api.github.com/';
        if (!empty($options->apiMirrorUrl)) {
            $apiBase = rtrim($options->apiMirrorUrl, '/') . '/';
        }
        return $apiBase;
    }

    private static function curlJson($url, $method = 'GET', $data = null, $token = '', $timeout = 20)
    {
        $headers = array(
            'User-Agent: Typecho-GitHub-Image-Upload',
        );
        if (!empty($token)) {
            $headers[] = 'Authorization: token ' . $token;
        }
        if ($data !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        if ($method === 'PUT' || $method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        } elseif ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        }
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        self::log("HTTP {$method} {$code} -> {$url}");
        if ($err) {
            self::log('cURL错误: ' . $err);
            return null;
        }
        $json = json_decode($resp, true);
        return $json;
    }

    private static function extractRelativeFromUrl($url, $pluginOptions)
    {
        try {
            $branch = !empty($pluginOptions->githubBranch) ? $pluginOptions->githubBranch : 'main';
            // 支持 raw.githubusercontent、传统镜像、gh-proxy 三种形式
            // 统一移除前缀直到 /{user}/{repo}/{branch}/images/
            $patterns = array(
                '#https?://raw\.githubusercontent\.com/[^/]+/[^/]+/' . preg_quote($branch, '#') . '/images/(.+)$#i',
            );
            if (!empty($pluginOptions->mirrorUrl)) {
                $mirror = rtrim($pluginOptions->mirrorUrl, '/');
                $mode = isset($pluginOptions->mirrorMode) ? (string)$pluginOptions->mirrorMode : 'traditional';
                if ($mode === 'gh-proxy') {
                    // https://proxy/https://raw.githubusercontent.com/user/repo/branch/images/rel
                    $patterns[] = '#^' . preg_quote($mirror, '#') . '/https?://raw\.githubusercontent\.com/[^/]+/[^/]+/' . preg_quote($branch, '#') . '/images/(.+)$#i';
                } else {
                    // traditional: mirror replaces host, e.g. https://raw.kkgithub.com/user/repo/branch/images/rel
                    $patterns[] = '#^' . preg_quote($mirror, '#') . '/[^/]+/[^/]+/' . preg_quote($branch, '#') . '/images/(.+)$#i';
                }
            }
            foreach ($patterns as $p) {
                if (preg_match($p, $url, $m)) {
                    return $m[1];
                }
            }
        } catch (Exception $e) {
            self::log('extractRelativeFromUrl异常: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * 确保目录存在
     */
    private static function ensureDirectoryExists($path, $options)
    {
        // GitHub Contents API 支持直接 PUT 文件到多级路径，无需预创建目录
        // 为兼容老日志，保留最小日志并返回 true
        self::log("跳过目录预创建，直接上传文件");
        return true;
    }

    /**
     * 上传文件到GitHub
     */
    private static function uploadToGitHub($file, $path, $options)
    {
        // 直接上传，无需预创建目录
        // 支持 API 镜像：当配置 apiMirrorUrl 且非空时，替换 https://api.github.com/
        $apiBase = 'https://api.github.com/';
        if (!empty($options->apiMirrorUrl)) {
            $apiMirror = rtrim($options->apiMirrorUrl, '/') . '/';
            // 仅允许传统镜像（含 api. 关键词）避免代理前缀型再次造成双重URL
            if (strpos($apiMirror, 'api.') !== false) {
                $apiBase = $apiMirror;
            }
        }
        $url = $apiBase . "repos/{$options->githubUser}/{$options->githubRepo}/contents/images/{$path}";
        
        self::log("GitHub API URL: " . $url);
        
        // 读取文件内容（如启用图片优化则优先采用优化后的字节内容）
        $fileContent = self::maybeOptimizeImage($file, $options);
        if ($fileContent === false) {
            self::log("无法读取文件内容");
            return false;
        }
        
        $data = array(
            'message' => 'Upload image via Typecho',
            'content' => base64_encode($fileContent),
            'branch' => (!empty($options->githubBranch) ? $options->githubBranch : 'main')
        );
        
        $headers = array(
            'Authorization: token ' . $options->githubToken,
            'Content-Type: application/json',
            'User-Agent: Typecho-GitHub-Image-Upload'
        );
        
        self::log("发送GitHub API请求: " . $url);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        self::log("API响应: HTTP " . $httpCode);
        if ($curlError) {
            self::log("cURL错误: " . $curlError);
        }
        if ($httpCode !== 201) {
            self::log("API响应内容: " . substr($response, 0, 500));
        }
        
        return $httpCode === 201;
    }
    /**
     * 尝试对图片进行压缩/缩放优化，仅对 JPG/PNG/WebP 生效。
     * 返回：字符串（二进制字节）或 false
     */
    private static function maybeOptimizeImage($file, $options)
    {
        try {
            // 可通过设置开关控制是否进行优化
            if (isset($options->enableOptimization) && (string)$options->enableOptimization !== '1') {
                return @file_get_contents($file['tmp_name']);
            }
            // 智能优化（GD 不可用则回退）

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, array('jpg', 'jpeg', 'png', 'webp'))) {
                return @file_get_contents($file['tmp_name']);
            }

            if (!function_exists('imagecreatefromstring')) {
                self::log('GD 扩展不可用，跳过图片优化');
                return @file_get_contents($file['tmp_name']);
            }
            $raw = @file_get_contents($file['tmp_name']);
            if ($raw === false) {
                return false;
            }

            $im = @imagecreatefromstring($raw);
            if (!$im) {
                // 不是标准可解析图片，返回原始内容
                return $raw;
            }
            $width = imagesx($im);
            $height = imagesy($im);
            // 智能默认：最长边不超过 2560px
            $maxW = 2560;
            $maxH = 2560;
            $scale = 1.0;
            if ($maxW > 0 && $width > $maxW) {
                $scale = min($scale, $maxW / $width);
            }
            if ($maxH > 0 && $height > $maxH) {
                $scale = min($scale, $maxH / $height);
            }
            if ($scale < 1.0) {
                $newW = max(1, (int)($width * $scale));
                $newH = max(1, (int)($height * $scale));
                $dst = imagecreatetruecolor($newW, $newH);
                // 保持透明通道
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagecopyresampled($dst, $im, 0, 0, 0, 0, $newW, $newH, $width, $height);
                imagedestroy($im);
                $im = $dst;
            }

            // 智能默认：质量 85
            $quality = 85;

            ob_start();
            if ($ext === 'jpg' || $ext === 'jpeg') {
                imageinterlace($im, 1);
                imagejpeg($im, null, $quality);
            } elseif ($ext === 'png') {
                // PNG 压缩等级 0(无损/大) - 9(体积小/有损)，由 0-100 质量映射
                $level = (int)round((100 - $quality) * 9 / 100);
                $level = max(0, min(9, $level));
                imagesavealpha($im, true);
                imagepng($im, null, $level);
            } else { // webp
                if (function_exists('imagewebp')) {
                    imagewebp($im, null, $quality);
                } else {
                    // 环境不支持 webp 编码，回退原始
                    ob_end_clean();
                    imagedestroy($im);
                    return $raw;
                }
            }
            $out = ob_get_clean();
            imagedestroy($im);
            if ($out === false || $out === '') {
                return $raw;
            }
            return $out;
        } catch (Exception $e) {
            self::log('图片优化异常: ' . $e->getMessage());
            return @file_get_contents($file['tmp_name']);
        }
    }
}
?>




