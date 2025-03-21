# Typecho 博客集成 Apprise 通知插件 - Apprisify

A plugin for Typecho blogs that integrates with AppriseAPI to deliver real-time notifications.

一个用于 Typecho 博客系统的通知插件，在收到新评论/留言时通过 [Apprise API](https://github.com/caronc/apprise-api) 推送通知到多种服务，包括 Telegram、Email、Discord 等，通知服务列表参考 [🔗 Apprise](https://github.com/caronc/apprise/wiki#notification-services)。

[🔗 Github](https://github.com/hqoq/Apprisify-for-Typecho)

## 功能特点

- 接收新评论/留言通知
- 待审核评论通知
- 支持 100+ 种通知服务（由 Apprise 提供）
- 自定义通知模板
- 配置简单，直接使用通知 URL
- 内置简单测试工具
- 记录通知日志

## 安装要求

- 依赖 `Apprise API` 工作

### 安装说明

1. 下载仓库文件并解压
2. 将文件夹 `Apprisify` 上传到 Typecho 插件目录：`/usr/plugins/`
3. 登录控制台，在 `插件管理` 中找到 `Apprisify` 并启用
4. 根据需要进行相关设置

## 自定义通知模板

使用以下变量自定义通知内容：

- `{author}` - 评论者称呼
- `{title}` - 文章标题
- `{content}` - 评论内容
- `{permalink}` - 评论链接
- `{status}` - 评论状态（已通过/待审核）

## 安全考虑

直接 URL 通知包含敏感凭据 (如 API Key)，建议本地部署 `Apprise API`，并限制外部访问。

如需外部访问，建议设置 Nginx 反向代理、启用 HTTPS 并设置访问控制且定期检查服务器日志。

## 许可证

本插件采用 MIT 许可证发布。详见 [LICENSE](LICENSE) 文件。
