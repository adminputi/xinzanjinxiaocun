# 鑫瓒进销存管理系统 V1.3

基于 PHP + MySQL 的进销存管理系统，纯原生 PHP 开发，无框架依赖。

## 技术栈

- PHP 原生（无框架）
- MySQL（PDO + InnoDB + utf8mb4）
- Chart.js + Font Awesome
- 前端原生 JavaScript

## 快速开始

1. 将项目部署到 PHP 环境（PHP 7.4+，MySQL 5.7+）
2. 访问 `install/index.php` 运行安装向导
3. 设置数据库信息和管理员账号
4. 安装完成后删除 `install/` 目录

## 功能模块

- 仪表盘：经营数据概览、图表展示
- 销售管理：订单、出库、退货、打印模板
- 采购管理：订单、入库、退货
- 库存管理：库存查询、调拨、报损报溢
- CRM客户关系管理：客户管理、跟进记录、公海池、数据报表
- 财务管理：收支流水、应收应付
- 售后溯源：追踪码生成、二维码扫码溯源
- 系统设置：用户权限、系统参数配置
- 授权管理：在线授权验证

## 目录结构

```
├── index.php          # 登录入口
├── dashboard.php      # 仪表盘
├── track.php          # 溯源查询
├── api/               # API 接口
├── assets/            # 静态资源
├── config/            # 配置文件（安装时生成）
├── includes/          # 核心函数库
├── install/           # 安装向导
├── modules/           # 业务模块
└── uploads/           # 上传文件目录
```

## 安全提示

- 生产环境请删除 `install/` 目录
- 修改 `config/database.php` 中 `PRODUCTION_MODE` 为 `true`

## 版本

当前版本：V1.3
