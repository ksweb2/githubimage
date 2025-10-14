# GitHubImageUpload (Typecho 插件)

将图片附件上传到 GitHub 仓库，并在编辑器与内容中以直链形式展示；支持镜像加速（传统 raw 域 与 gh-proxy）。非图片文件（如 mp3/zip/pdf）继续走 Typecho 默认本地上传流程，互不干扰。

## 功能特性
- 图片直传 GitHub（Contents API），保存为 `images/YYYY/MM/DD/<unique>.<ext>`
- 镜像加速（可选）：
  - 传统 raw 域：`https://raw.kkgithub.com/<user>/<repo>/main/images/...`
  - gh-proxy 前缀：`https://hk.gh-proxy.com/https://raw.githubusercontent.com/<user>/<repo>/main/images/...`
- API 上传加速（可选）：将 `https://api.github.com/` 替换为传统 API 镜像（如 `https://api.kkgithub.com/`）
- 编辑器注入：仅对图片扩展名改写为直链/镜像；非图片不改写
- 内容过滤：发布后将本地图片 URL 替换为 GitHub 直链/镜像 URL
- 健壮日志：记录上传请求与错误，便于排障

## 安装
1. 将本目录放到 `usr/plugins/GitHubImageUpload`
2. 后台启用插件
3. 打开插件设置完成配置

## 配置项
- GitHub Token：具备目标仓库内容写入权限（repo）
- GitHub 用户名：如 `ksweb2`
- GitHub 仓库名：如 `tuchuang`
- 使用镜像：是否启用内容镜像
- 内容镜像地址（可选）：
  - 传统镜像（raw 域）：如 `https://raw.kkgithub.com/`
  - gh-proxy 代理根：如 `https://hk.gh-proxy.com`
- API 镜像地址（可选）：传统镜像 API 域，如 `https://api.kkgithub.com/`
  - 启用后上传时以该域替换 `https://api.github.com/`
  - 仅建议填写含 `api.` 的传统镜像，避免前缀代理引发双重 URL

## 使用
- 编辑器上传图片：图片直传 GitHub；插入到编辑器的 URL 为原始/镜像直链
- 编辑器上传非图片：按 Typecho 默认流程保存到本地 `/usr/uploads`，URL 不改写
- 发布后：内容中的本地图片 URL 会被替换为 GitHub 直链/镜像 URL

## 行为/实现细节
- 不预创建目录与 `.gitkeep`，直接对多级路径执行 PUT
- 仅图片扩展名会被镜像改写：`jpg|jpeg|png|gif|webp|bmp|svg`
- 上传失败会记录日志并返回错误；非图片流程不受影响

## 日志
- 路径：`GitHubImageUpload/log/github_upload_YYYY-MM-DD.log`
- 记录：配置状态、API URL、HTTP 状态码、错误信息等

## 故障排除
- 401 Bad credentials：检查 Token 是否正确/未过期，是否包含 repo 权限
- 404 Not Found：检查用户名/仓库名/分支与路径是否正确
- 500 上传失败但刷新后附件可见：查看日志中的 GitHub API 响应；确认镜像配置未造成双重 URL

## FAQ
- 访问文章时图片由谁请求？
  - 用户浏览器直接请求 GitHub 或镜像直链，服务器不转发；因此镜像能直接改善读者侧的加载速度与可用性。
- 是否需要反向代理/缓存？
  - 非必须。若读者访问 raw 较慢，可配置镜像；也可在 CDN/前端做缓存。
- 镜像如何填写？
  - 传统内容镜像：`https://raw.kkgithub.com/`
  - gh-proxy 内容镜像：`https://hk.gh-proxy.com`
  - 传统 API 镜像：`https://api.kkgithub.com/`

## 兼容性
- 需要 PHP cURL
- 适配 Typecho 核心上传与编辑器常见场景

## 许可证
MIT