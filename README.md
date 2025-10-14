# GitHub图床插件使用说明

## 🔧 问题解决

### GitHub Token认证问题
根据您提供的日志，问题出现在GitHub API返回401错误"Bad credentials"。这通常意味着：

1. **Token已过期** - GitHub Personal Access Token可能已过期
2. **Token权限不足** - Token可能没有足够的权限访问仓库
3. **Token格式错误** - Token格式可能不正确

### 解决方案

#### 1. 重新生成GitHub Token
1. 访问 [GitHub Settings > Developer settings > Personal access tokens](https://github.com/settings/tokens)
2. 点击 "Generate new token (classic)"
3. 设置过期时间（建议选择较长时间）
4. 选择权限范围：
   - ✅ `repo` (完整仓库访问权限)
   - ✅ `public_repo` (公开仓库访问权限)
5. 复制生成的Token（以`ghp_`开头）

#### 2. 更新插件配置
1. 进入Typecho后台 > 控制台 > 插件
2. 找到GitHub图床插件，点击"设置"
3. 更新GitHub Token字段
4. 点击"测试连接"验证配置

## 🚀 新增功能

### API镜像支持
现在支持使用GitHub API镜像来加速访问：

#### 配置选项
- **API镜像地址**: 如 `https://api.kkgithub.com/`
- **是否使用API镜像**: 启用/禁用API镜像

#### 支持的镜像服务
- `https://api.kkgithub.com/` - kkgithub镜像
- `https://api.github.com.cnpmjs.org/` - cnpmjs镜像
- 其他兼容的GitHub API镜像

### 改进的错误处理
- 更详细的错误提示
- Token验证失败时的具体说明
- 仓库访问权限检查
- 分支配置验证

## 📋 完整配置指南

### 必需配置
1. **GitHub Token**: 具有repo权限的Personal Access Token
2. **GitHub用户名**: 您的GitHub用户名或组织名
3. **GitHub仓库名**: 用于存储图片的仓库名称

### 可选配置
1. **GitHub分支**: 默认为`main`
2. **存储路径**: 默认为`images`
3. **目录结构**: 按日期分类或平铺存储
4. **图片压缩**: 启用/禁用，设置质量和最大尺寸
5. **镜像加速**: 
   - GitHub镜像地址（用于图片访问）
   - API镜像地址（用于API请求）

## 🔍 故障排除

### 常见错误及解决方案

#### 401 Bad credentials
- 检查Token是否正确
- 确认Token未过期
- 验证Token权限包含`repo`

#### 404 Not Found
- 检查仓库名是否正确
- 确认仓库存在且可访问
- 验证用户名/组织名正确

#### 403 Forbidden
- 检查Token权限是否足够
- 确认仓库访问权限
- 验证分支名称正确

### 调试方法
1. 查看插件日志文件：`/GitHubImageUpload/logs/`
2. 使用调试页面：访问`debug.php`
3. 使用测试功能：访问`test.php`

## 📝 更新日志

### v2.0.0
- ✅ 修复GitHub Token认证问题
- ✅ 添加API镜像支持
- ✅ 改进错误处理和用户提示
- ✅ 优化代码结构和性能
- ✅ 简化配置流程

## 🆘 技术支持

如果遇到问题，请：
1. 检查日志文件获取详细错误信息
2. 使用测试连接功能验证配置
3. 确认GitHub Token权限和有效性
4. 检查网络连接和镜像服务状态