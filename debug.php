<?php
/**
 * GitHub图床插件
 * 
 * @package GitHubImageUpload
 * @author ksweb2
 * @version 3.0.0
 * @link https://github.com/ksweb2/githubimage
 */

// 防止直接访问
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

// 处理调试请求
if (isset($_GET['action']) && $_GET['action'] === 'debug') {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $options = Typecho_Widget::widget('Widget_Options');
        $pluginOptions = $options->plugin('GitHubImageUpload');
        
        if (!$pluginOptions) {
            echo json_encode(array('success' => false, 'message' => '插件未激活'));
            exit;
        }
        
        // 检查钩子是否正确注册
        $hooks = Typecho_Plugin::export();
        $uploadHooks = isset($hooks['Widget_Upload']['uploadHandle']) ? $hooks['Widget_Upload']['uploadHandle'] : array();
        
        $debugInfo = array(
            'plugin_active' => true,
            'config_exists' => !empty($pluginOptions->githubToken),
            'upload_hooks' => $uploadHooks,
            'plugin_options' => array(
                'githubUser' => $pluginOptions->githubUser,
                'githubRepo' => $pluginOptions->githubRepo,
                'githubPath' => $pluginOptions->githubPath,
                'githubBranch' => $pluginOptions->githubBranch,
                'useMirror' => $pluginOptions->useMirror,
                'githubMirror' => $pluginOptions->githubMirror
            )
        );
        
        echo json_encode($debugInfo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        echo json_encode(array('success' => false, 'message' => '调试失败: ' . $e->getMessage()));
    }
    
    exit;
}

// 显示调试页面
?>
<!DOCTYPE html>
<html>
<head>
    <title>GitHub图床插件调试</title>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .debug-info { background: #f5f5f5; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .success { color: #28a745; }
        .error { color: #dc3545; }
        .warning { color: #ffc107; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 3px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>GitHub图床插件调试页面</h1>
    
    <div class="debug-info">
        <h3>调试信息</h3>
        <button onclick="loadDebugInfo()">加载调试信息</button>
        <div id="debug-result"></div>
    </div>
    
    <div class="debug-info">
        <h3>使用说明</h3>
        <p>1. 点击"加载调试信息"查看插件状态</p>
        <p>2. 检查上传钩子是否正确注册</p>
        <p>3. 确认插件配置是否正确</p>
        <p>4. 如果钩子未注册，请重新激活插件</p>
    </div>
    
    <script>
    function loadDebugInfo() {
        var resultDiv = document.getElementById('debug-result');
        resultDiv.innerHTML = '加载中...';
        
        fetch('?action=debug')
        .then(response => response.json())
        .then(data => {
            resultDiv.innerHTML = '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
        })
        .catch(error => {
            resultDiv.innerHTML = '<div class="error">加载失败: ' + error.message + '</div>';
        });
    }
    </script>
</body>
</html>