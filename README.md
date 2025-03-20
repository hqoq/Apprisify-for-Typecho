# Apprisify for Typecho

A plugin for Typecho blogs that integrates with AppriseAPI to deliver real-time notifications.

一个用于 Typecho 博客系统的通知插件，通过 [Apprise API](https://github.com/caronc/apprise-api) 在博客收到新评论/留言时发送通知到多种通知服务，包括 Telegram、Email、Discord 等。

通知服务完整列表请参考 [Apprise 文档](https://github.com/caronc/apprise/wiki#notification-services)。

## 功能特点

- 接收新评论/留言通知
- 支持评论审核通知
- 支持 100+ 种通知服务（由 Apprise 提供）
- 自定义通知模板
- 配置简单，直接使用通知 URL
- 内置简单测试工具
- 记录通知日志

## 安装要求

- 依赖于 Apprise API 工作

### 安装插件

1. 下载本插件目录
2. 解压并将文件夹重命名为 `Apprisify`
3. 上传到 Typecho 的 `/usr/plugins/` 目录
4. 在 Typecho 后台启用插件

## 自定义通知模板

使用以下变量自定义通知内容：

- `{author}` - 评论者称呼
- `{title}` - 文章标题
- `{content}` - 评论内容
- `{permalink}` - 评论链接
- `{status}` - 评论状态（已通过/待审核）

## 安全考虑

使用通知 URL 包含敏感凭据（如 API Key），建议本地部署 Apprise API，限制外部访问。

如需外部访问，建议设置 Nginx 反向代理、启用 HTTPS 并设置访问控制并定期检查服务器日志。

## 许可证

本项目采用 MIT 许可证。详见 [LICENSE](LICENSE) 文件。
