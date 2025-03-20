<?php

/**
 * 将 Apprise 通知功能集成到 Typecho 博客，当收到评论或留言时发送通知
 * 插件直接通过 URL 发送通知，适用于 Apprise API v1.9+
 * 
 * @package Apprisify
 * @author Claude 3.7 & hqoq
 * @version 1.5
 * @link https://github.com/hqoq/apprisify-for-typecho
 */

class Apprisify_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法，如果激活失败，直接抛出异常
     * 
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        // 创建数据表
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();

        // 创建通知日志记录
        $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}apprise_notification_log` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `comment_id` int(11) DEFAULT NULL,
            `title` varchar(200) DEFAULT NULL,
            `body` text,
            `status` varchar(20) DEFAULT NULL,
            `created_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

        // 添加评论挂钩
        Typecho_Plugin::factory('Widget_Feedback')->comment = array('Apprisify_Plugin', 'sendNotification');
        Typecho_Plugin::factory('Widget_Comments_Edit')->edit = array('Apprisify_Plugin', 'sendNotificationOnEdit');

        return _t('插件已启用');
    }

    /**
     * 禁用插件方法，如果禁用失败，直接抛出异常
     * 
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        // 默认不删除日志表以保留记录
        return _t('插件已禁用');
    }

    /**
     * 获取插件配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // 处理测试请求
        if (isset($_GET['apprisify_test'])) {
            $result = self::testNotification();
            if ($result) {
                Typecho_Widget::widget('Widget_Notice')->set(_t('测试通知发送成功！'), 'success');
            } else {
                Typecho_Widget::widget('Widget_Notice')->set(_t('测试通知发送失败，请检查配置和日志'), 'error');
            }
        }

        // 添加说明文字
        $explanation = new Typecho_Widget_Helper_Layout('div', array('class' => 'typecho-page-title'));
        $explanation->html(_t('
            <h4>Apprise 插件配置说明</h4>
            <p>插件依赖 <a href="https://github.com/caronc/apprise-api" target="_blank">Apprise API</a>，考虑个人博客通知和配置便利性，插件仅通过 URL 直接通知，无需配置标签。</p>
            <ol>
                <li>设置 Apprise API 地址</li>
                <li>正确填写 Apprise URLs</li>
                <li>选择评论审核通知模式</li>
                <li>自定义通知模板</li>
            </ol>
        '));
        $form->addItem($explanation);

        // API 地址
        $apiUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'apiUrl',
            null,
            'http://localhost:8000/notify',
            _t('Apprise API 地址'),
            _t('Apprise API 的完整地址，如果在同一台服务器上，通常为 http://localhost:8000/notify')
        );
        $form->addInput($apiUrl);

        // 通知 URL
        $notifyUrls = new Typecho_Widget_Helper_Form_Element_Textarea(
            'notifyUrls',
            null,
            '',
            _t('通知服务URL'),
            _t('输入 <a href="https://github.com/caronc/apprise/wiki" target="_blank">Apprise URLs</a>，支持多个 URLs，每行一个。<br>例如：<br>tgram://{bot_token}/{chat_id}<br>mailto://{user}:{password}@gmail.com')
        );
        $form->addInput($notifyUrls);

        // 测试按钮
        $testBtn = new Typecho_Widget_Helper_Layout('div');
        $testUrl = Typecho_Common::url('/options-plugin.php?config=Apprisify&apprisify_test=1', Helper::options()->adminUrl);
        $testBtn->html('<a href="' . $testUrl . '" class="btn primary" style="text-decoration:none !important; color:#FFF !important; display:inline-flex !important; align-items:center !important">' . _t('发送测试通知') . '</a>');
        $form->addItem($testBtn);

        // 通知类型
        $notifyType = new Typecho_Widget_Helper_Form_Element_Select(
            'notifyType',
            array(
                'info' => _t('信息 (info)'),
                'success' => _t('成功 (success)'),
                'warning' => _t('警告 (warning)'),
                'failure' => _t('失败 (failure)')
            ),
            'info',
            _t('通知类型'),
            _t('发送的通知类型，不同类型在某些通知服务中会有不同的显示样式')
        );
        $form->addInput($notifyType);

        // 评论审核设置
        $moderationMode = new Typecho_Widget_Helper_Form_Element_Radio(
            'moderationMode',
            array(
                'all' => _t('所有评论都发送通知'),
                'pending_only' => _t('仅对待审核的评论发送通知'),
                'approved_only' => _t('仅对已批准的评论发送通知')
            ),
            'pending_only',
            _t('评论审核通知设置'),
            _t('根据博客的评论审核策略选择合适的通知模式')
        );
        $form->addInput($moderationMode);

        // 待审核评论通知标题模板
        $pendingTitleTemplate = new Typecho_Widget_Helper_Form_Element_Text(
            'pendingTitleTemplate',
            null,
            '{blogTitle} 有新评论待审核',
            _t('待审核评论通知标题'),
            _t('待审核评论的通知标题，支持 {blogTitle} 表示博客标题，{title} 表示文章标题')
        );
        $form->addInput($pendingTitleTemplate);

        // 待审核评论通知内容模板
        $pendingBodyTemplate = new Typecho_Widget_Helper_Form_Element_Textarea(
            'pendingBodyTemplate',
            null,
            '《{title}》
称呼：{author}
内容：{content}}',
            _t('待审核评论通知内容'),
            _t('待审核评论的通知内容，支持变量：{author}, {title}, {content}, {permalink}, {status}')
        );
        $form->addInput($pendingBodyTemplate);

        // 已通过评论通知标题模板
        $approvedTitleTemplate = new Typecho_Widget_Helper_Form_Element_Text(
            'approvedTitleTemplate',
            null,
            '{blogTitle} 收到了新评论',
            _t('已通过评论通知标题'),
            _t('已通过评论的通知标题，支持 {blogTitle} 表示博客标题，{title} 表示文章标题')
        );
        $form->addInput($approvedTitleTemplate);

        // 已通过评论通知内容模板
        $approvedBodyTemplate = new Typecho_Widget_Helper_Form_Element_Textarea(
            'approvedBodyTemplate',
            null,
            '《{title}》
称呼：{author}
内容：{content}
链接：{permalink}',
            _t('已通过评论通知内容'),
            _t('已通过评论的通知内容，支持相同的变量')
        );
        $form->addInput($approvedBodyTemplate);

        // 调试模式
        $debugMode = new Typecho_Widget_Helper_Form_Element_Radio(
            'debugMode',
            array('0' => _t('关闭'), '1' => _t('开启')),
            '0',
            _t('调试模式'),
            _t('开启后，将在系统日志中记录详细信息，有助于排查问题')
        );
        $form->addInput($debugMode);
    }

    /**
     * 个人用户的配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    /**
     * 处理评论提交
     * 
     * @access public
     * @param array $comment 评论数据
     * @param Widget_Comments_Edit|Widget_Feedback $feedback 评论组件
     * @return array
     */
    public static function sendNotification($comment, $feedback)
    {
        // 获取评论状态
        $status = isset($comment['status']) ? $comment['status'] : 'approved';

        // 记录调试信息
        self::debug("评论提交，状态: " . $status);

        // 不处理垃圾评论
        if ($status === 'spam') {
            return $comment;
        }

        $moderationMode = self::getOption('moderationMode', 'pending_only');

        // 检查评论审核设置
        if ($moderationMode === 'pending_only' && $status !== 'waiting') {
            self::debug("仅通知待审核评论模式，跳过已批准评论");
            return $comment;
        } elseif ($moderationMode === 'approved_only' && $status !== 'approved') {
            self::debug("仅通知已批准评论模式，跳过待审核评论");
            return $comment;
        }

        // 获取评论相关信息并发送通知
        try {
            self::processCommentNotification($comment, $status === 'waiting' ? 'pending' : 'approved');
        } catch (Exception $e) {
            self::debug("发送通知时出错: " . $e->getMessage());
        }

        // 返回原始评论，确保流程继续
        return $comment;
    }

    /**
     * 处理评论编辑
     * 
     * @access public
     * @param array $comment 评论数据
     * @param Widget_Comments_Edit $edit 评论编辑组件
     * @return array
     */
    public static function sendNotificationOnEdit($comment, $edit)
    {
        $moderationMode = self::getOption('moderationMode', 'pending_only');

        // 当评论从待审核变为已批准时
        if ($comment['status'] === 'approved' && isset($edit->status) && $edit->status !== 'approved') {
            self::debug("评论状态改变: {$edit->status} -> approved");

            // 检查审核通知设置
            if ($moderationMode !== 'all' && $moderationMode !== 'approved_only') {
                self::debug("根据配置，跳过审核通过通知");
                return $comment;
            }

            try {
                self::processCommentNotification($comment, 'approved');
            } catch (Exception $e) {
                self::debug("发送编辑通知时出错: " . $e->getMessage());
            }
        }

        return $comment;
    }

    /**
     * 处理评论通知
     * 
     * @access private
     * @param array $comment 评论数据
     * @param string $type 通知类型 ('pending' 或 'approved')
     * @return void
     */
    private static function processCommentNotification($comment, $type = 'pending')
    {
        // 获取评论 ID 和内容 ID
        $coid = isset($comment['coid']) ? $comment['coid'] : 0;
        $cid = isset($comment['cid']) ? $comment['cid'] : 0;

        self::debug("处理评论 ID: {$coid}, 内容 ID: {$cid}, 类型: {$type}");

        if ($cid <= 0) {
            self::debug("无效的内容ID: {$cid}");
            return;
        }

        // 获取文章信息
        $db = Typecho_Db::get();
        $content = $db->fetchRow($db->select()->from('table.contents')->where('cid = ?', $cid));

        if (!$content) {
            self::debug("未找到内容: {$cid}");
            return;
        }

        // 正确获取文章标题
        $title = isset($content['title']) ? $content['title'] : '未知文章';
        self::debug("文章标题: {$title}");

        // 获取博客标题
        $blogTitle = '';
        try {
            $options = Helper::options();
            $blogTitle = $options->title;
        } catch (Exception $e) {
            $blogTitle = '博客';
            self::debug("获取博客标题失败: " . $e->getMessage());
        }

        // 根据通知类型选择不同的模板
        if ($type === 'pending') {
            $titleTemplate = self::getOption('pendingTitleTemplate', '{blogTitle} 有新评论待审核');
            $bodyTemplate = self::getOption('pendingBodyTemplate', '{author} 在文章《{title}》中发表了评论（待审核）：{content}');
        } else {
            $titleTemplate = self::getOption('approvedTitleTemplate', '{blogTitle} 收到了新评论');
            $bodyTemplate = self::getOption('approvedBodyTemplate', '{author} 在文章《{title}》中说：{content} 链接：{permalink}');
        }

        // 替换模板变量
        $status = isset($comment['status']) ? ($comment['status'] == 'approved' ? '已通过' : '待审核') : '未知';
        $author = isset($comment['author']) ? $comment['author'] : '匿名';
        $text = isset($comment['text']) ? $comment['text'] : '(无内容)';

        // 安全获取网站 URL
        $siteUrl = '';
        try {
            $options = Helper::options();
            $siteUrl = $options->siteUrl;
        } catch (Exception $e) {
            $siteUrl = '';
        }

        $permalink = $siteUrl . (strpos($siteUrl, '?') !== false ? '&' : '?') .
            'cid=' . $cid;

        if ($coid > 0) {
            $permalink .= '#comment-' . $coid;
        }

        $notifyTitle = str_replace(
            array('{title}', '{blogTitle}'),
            array($title, $blogTitle),
            $titleTemplate
        );

        $notifyBody = str_replace(
            array('{author}', '{title}', '{content}', '{permalink}', '{status}', '{blogTitle}'),
            array($author, $title, $text, $permalink, $status, $blogTitle),
            $bodyTemplate
        );

        self::debug("生成通知标题: {$notifyTitle}");
        self::debug("生成通知内容长度: " . strlen($notifyBody));

        // 记录日志
        self::logNotification($coid, $notifyTitle, $notifyBody, '发送中');

        // 发送通知
        $result = self::sendViaAppriseApi($notifyTitle, $notifyBody);

        // 更新日志状态
        $status = $result ? '成功' : '失败';
        self::updateLogStatus($coid, $status);
    }

    /**
     * 记录通知日志
     * 
     * @access private
     * @param int $commentId 评论 ID
     * @param string $title 通知标题
     * @param string $body 通知内容
     * @param string $status 状态
     * @return void
     */
    private static function logNotification($commentId, $title, $body, $status)
    {
        $db = Typecho_Db::get();
        $date = new Typecho_Date();

        try {
            $db->query($db->insert('table.apprise_notification_log')
                ->rows(array(
                    'comment_id' => $commentId,
                    'title' => $title,
                    'body' => $body,
                    'status' => $status,
                    'created_at' => $date->format('Y-m-d H:i:s')
                )));
        } catch (Exception $e) {
            self::debug("记录日志时出错: " . $e->getMessage());
        }
    }

    /**
     * 更新日志状态
     * 
     * @access private
     * @param int $commentId 评论 ID
     * @param string $status 状态
     * @return void
     */
    private static function updateLogStatus($commentId, $status)
    {
        if ($commentId <= 0) {
            return;
        }

        $db = Typecho_Db::get();

        try {
            $db->query($db->update('table.apprise_notification_log')
                ->rows(array('status' => $status))
                ->where('comment_id = ?', $commentId)
                ->order('id', Typecho_Db::SORT_DESC)
                ->limit(1));
        } catch (Exception $e) {
            self::debug("更新日志状态时出错: " . $e->getMessage());
        }
    }

    /**
     * 安全获取插件配置
     * 
     * @access private
     * @param string $key 配置键名
     * @param mixed $default 默认值
     * @return mixed 配置值
     */
    private static function getOption($key, $default = null)
    {
        try {
            $options = Helper::options();
            if ($options && isset($options->plugin('Apprisify')->$key)) {
                return $options->plugin('Apprisify')->$key;
            }
        } catch (Exception $e) {
            self::debug("获取配置项 {$key} 出错: " . $e->getMessage());
        }

        return $default;
    }

    /**
     * 通过 Apprise API 发送通知
     * 
     * @access private
     * @param string $title 通知标题
     * @param string $body 通知内容
     * @return bool 是否发送成功
     */
    private static function sendViaAppriseApi($title, $body)
    {
        // 安全获取配置
        $apiUrl = self::getOption('apiUrl', 'http://localhost:8000/notify');
        $notifyUrls = self::getOption('notifyUrls', '');
        $notifyType = self::getOption('notifyType', 'info');

        if (empty($apiUrl)) {
            self::debug('Apprise API URL not configured');
            return false;
        }

        if (empty($notifyUrls)) {
            self::debug('Notification URLs not configured');
            return false;
        }

        // 处理多行 URL
        $urlArray = array_filter(array_map('trim', explode("\n", $notifyUrls)));

        // 准备 POST 数据
        $postData = [
            'urls' => implode(',', $urlArray),
            'title' => $title,
            'body' => $body,
            'type' => $notifyType
        ];

        self::debug("发送通知到 {$apiUrl}");
        self::debug("URLs: " . implode(',', $urlArray));

        // 使用 cURL 发送请求
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        // 安全获取调试模式设置
        $debugMode = self::getOption('debugMode', '0');

        // 开启详细日志记录
        if ($debugMode == '1') {
            $verbose = fopen('php://temp', 'w+');
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            curl_setopt($ch, CURLOPT_STDERR, $verbose);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // 记录详细的连接日志
        if ($debugMode == '1' && isset($verbose)) {
            rewind($verbose);
            $verboseLog = stream_get_contents($verbose);
            self::debug("cURL详细日志: " . $verboseLog);
            fclose($verbose);
        }

        if ($error || $httpCode >= 400) {
            self::debug("发送通知失败: HTTP Code: $httpCode, Error: $error");
            if ($response) {
                self::debug("Response: $response");
            }
            return false;
        }

        self::debug("通知发送成功: $response");
        return true;
    }

    /**
     * 测试通知功能
     * 
     * @access public
     * @return bool 是否发送成功
     */
    public static function testNotification()
    {
        // 安全获取配置
        $apiUrl = self::getOption('apiUrl', 'http://localhost:8000/notify');
        $notifyUrls = self::getOption('notifyUrls', '');

        // 记录测试开始
        self::debug("======== 开始通知测试 ========");
        self::debug("API地址: $apiUrl");
        self::debug("通知URLs: $notifyUrls");

        // 获取博客标题
        $blogTitle = '';
        try {
            $options = Helper::options();
            $blogTitle = $options->title;
        } catch (Exception $e) {
            $blogTitle = '博客';
        }

        // 准备测试数据
        $testTitle = "测试通知 - {$blogTitle}";
        $testBody = "这是一条来自Typecho的测试消息，发送时间: " . date('Y-m-d H:i:s');

        // 发送测试通知
        $result = self::sendViaAppriseApi($testTitle, $testBody);

        // 记录测试结果
        if ($result) {
            self::debug("测试通知发送成功！");
        } else {
            self::debug("测试通知发送失败！");
        }
        self::debug("======== 测试结束 ========");

        return $result;
    }

    /**
     * 调试日志
     * 
     * @access private
     * @param string $message 日志消息
     * @return void
     */
    private static function debug($message)
    {
        // 安全获取调试模式设置
        $debugMode = self::getOption('debugMode', '0');

        // 检查是否启用调试模式
        if ($debugMode == '1') {
            error_log('[Apprisify] ' . $message);
        }
    }
}
