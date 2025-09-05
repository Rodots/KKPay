# 开发规范
> 请理解并尽量遵循以下命名规范，可以减少在开发过程中出现不必要的错误。

## 目录和文件
- 目录使用小写+下划线
- 类库、函数文件统一以.php为后缀
- 类的文件名均以命名空间定义，路径和文件所在路径一致
- 类（包含接口和Trait）文件采用驼峰法命名（首字母大写），其它文件采用小写+下划线命名
- 类名（包括接口和Trait）和文件名保持一致，统一采用驼峰法命名（首字母大写）

## 函数和类、属性命名
- 类的命名采用驼峰法（首字母大写），例如 User、UserType
- 函数的命名使用小写字母和下划线（小写字母开头），例如 get_client_ip
- 方法的命名使用驼峰法（首字母小写），例如 getUserName
- 属性的命名使用驼峰法（首字母小写），例如 tableName、instance
- 特例：以双下划线__打头的函数或方法作为魔术方法，例如 __call 和 __autoload

## 常量和配置
- 常量以大写字母和下划线命名，例如 APP_PATH
- 配置参数以小写字母和下划线命名，例如 url_route_on 和url_convert
- 环境变量定义使用大写字母和下划线命名，例如APP_DEBUG

## 数据表和字段
- 数据表和字段采用小写加下划线方式命名，字段名不要以下划线开头
- 例如 think_user 表和 user_name字段，不建议使用驼峰和中文作为数据表及字段命名

## 代码格式
- 统一使用LF（\r）作为行分隔符
- 统一使用4个空格进行缩进，不要使用制表符进行缩进

## Git提交规范

### 提交类型标识
- feat：新功能（feature）
- fix：修补bug
- docs：文档（documentation）
- style：格式（不影响代码运行的变动）
- refactor：重构（即不是新增功能，也不是修改bug的代码变动）
- test：增加测试
- chore：构建过程或辅助工具的变动
- sql：有关数据库结构的变更

### 提交消息格式
每次提交，Commit message 都包括三个部分：Header，Body 和 Footer。

#### Header（必需）
格式：<type>(<scope>): <subject>
- type：提交类型，如 feat、fix 等
- scope：影响范围，可选，如 core、api、ui 等
- subject：简短描述，不超过50个字符

示例：feat(user): 添加用户登录功能

#### Body（可选）
对本次提交的详细描述，可以分成多行。建议72个字符换行。

#### Footer（可选）
- 不兼容变更：BREAKING CHANGE:
- 关联Issues：Closes #123, #456

### 提交规范示例
- feat(api): 实现用户注册接口
- fix(auth): 修复登录验证逻辑错误
- docs(readme): 更新安装说明
- style(format): 统一代码缩进格式
- refactor(core): 优化数据库连接逻辑
- test(unit): 添加用户模型单元测试
- chore(deps): 升级第三方依赖包
- sql(schema): 新增用户表结构

### 注意事项
- 提交前确保代码能正常运行
- 避免一次性提交过多无关更改
- 每次提交应该只有一个明确的目的
- 提交前检查是否有敏感信息泄露


> 请避免使用PHP保留字（保留字列表参见 https://www.php.net/manual/zh/reserved.keywords.php ）作为常量、类名和方法名，以及命名空间的命名，否则会造成系统错误。
