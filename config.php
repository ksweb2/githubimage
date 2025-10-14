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

// 处理测试连接请求
if (isset($_POST['testConnection'])) {
    $options = Typecho_Widget::widget('Widget_Options');
    $pluginOptions = $options->plugin('GitHubImageUpload');
    
    $result = GitHubImageUpload_Helper::testConnection($pluginOptions);
    
    if ($result['success']) {
        echo '<div class="message success">' . $result['message'] . '</div>';
    } else {
        echo '<div class="message error">' . $result['message'] . '</div>';
    }
    
    exit;
}