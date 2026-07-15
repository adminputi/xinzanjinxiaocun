# 鑫瓒进销存管理系统 - 项目文档

## 项目概述

基于 **PHP + MySQL** 的进销存管理系统（Inventory Management System），纯原生 PHP 开发，无框架依赖，PDO 连接数据库，前端原生 JavaScript + Chart.js + Font Awesome。

- **项目名称**：鑫瓒进销存管理系统
- **开发语言**：PHP（原生，无框架）
- **数据库**：MySQL（PDO，InnoDB，utf8mb4）
- **入口文件**：`index.php`（登录页）
- **主面板**：`dashboard.php`
- **安装向导**：`install/index.php`

---

## 目录结构

```
├── api/                           # API 接口（纯JSON输出）
│   └── get_orders.php             # 获取订单列表（收款/付款关联单据）
├── assets/                        # 静态资源
│   ├── css/style.css              # 主样式表 (~20KB)
│   └── js/main.js                 # 前端交互逻辑 (~15KB)
├── config/
│   └── database.php               # 数据库配置 + getDB() PDO单例
├── includes/                      # 核心公共文件
│   ├── auth.php                   # 认证登录 + 权限检查（RBAC）
│   ├── footer.php                 # 页脚（关闭标签）
│   ├── functions.php              # 公共函数库（核心，约80KB）
│   ├── header.php                 # 页面头部 + 侧边栏 + 面包屑导航
│   └── xlsx_helper.php            # Excel导入导出（纯PHP，ZipArchive+SimpleXML）
├── install/                       # 安装向导
│   ├── index.php                  # 3步安装：数据库配置→创建管理员→完成
│   ├── schema.sql                 # 完整建表SQL（28张表）
│   └── upgrade.php                # 数据库升级脚本
├── modules/                       # 功能模块
│   ├── finance/                   # 财务管理（应收应付/收款/付款/账龄分析）
│   ├── inventory/                 # 库存管理（实时库存/变动/调拨/盘点/报损报溢）
│   ├── product/                   # 主数据管理
│   │   ├── list.php               # 商品管理主列表
│   │   ├── category.php           # 商品分类
│   │   ├── unit.php               # 商品单位
│   │   ├── warehouse.php          # 仓库管理
│   │   ├── customer.php           # 客户管理
│   │   ├── customer_detail.php    # 客户详情
│   │   ├── supplier.php           # 供应商管理
│   │   ├── supplier_detail.php    # 供应商详情
│   │   ├── employee.php           # 员工管理
│   │   ├── import.php             # 数据导入（商品/客户/供应商批量导入）
│   │   ├── product_images.php     # 商品图片上传API（JSON接口）
│   │   └── ajax_sku.php           # 自动生成SKU编码
│   ├── purchase/                  # 采购管理（订单/入库/退货）
│   ├── report/                    # 报表分析（销售排行/采购统计/库存/业绩/出入库）
│   ├── sales/                     # 销售管理（订单/出库/退货/打印模板）
│   └── system/                    # 系统管理
│       ├── users.php              # 用户管理
│       ├── roles.php              # 角色权限管理
│       ├── backup.php             # 数据库备份与恢复
│       ├── logs.php               # 操作日志
│       ├── settings.php           # 系统设置
│       └── reset.php              # 系统初始化
├── uploads/                       # 上传文件目录（含tmp子目录）
├── .htaccess                      # Apache安全配置
├── index.php                      # 登录页面
├── dashboard.php                  # 仪表盘/首页看板
├── check.php                      # 环境诊断工具
├── logout.php                     # 登出
└── PROJECT.md                     # 本文件——项目文档
```

---

## 数据库结构（28张表）

### 系统基础表
| 表名 | 说明 | 备注 |
|------|------|------|
| `roles` | 角色表 | permissions 字段为 JSON 数组 |
| `users` | 用户表 | password 使用 bcrypt 哈希 |
| `operation_logs` | 操作日志 | 记录所有用户操作 |
| `system_settings` | 系统设置 | key-value 配置 |
| `print_templates` | 打印模板 | 存储 HTML 模板 |

### 主数据表
| 表名 | 说明 |
|------|------|
| `product_categories` | 商品分类（支持父子层级） |
| `units` | 商品单位（预置12个） |
| `products` | 商品表（SKU唯一，关联分类/单位） |
| `product_images` | 商品多图（product_id关联） |
| `warehouses` | 仓库（预置"主仓库"） |
| `suppliers` | 供应商 |
| `customers` | 客户（个体/企业） |
| `employees` | 员工 |

### 库存表
| 表名 | 说明 |
|------|------|
| `inventory` | 实时库存（product_id + warehouse_id 唯一约束） |
| `inventory_logs` | 库存变动记录 |

### 采购管理（6张表）
- `purchase_orders` + `purchase_order_items`（采购订单）
- `purchase_instocks` + `purchase_instock_items`（采购入库）
- `purchase_returns` + `purchase_return_items`（采购退货）

### 销售管理（6张表）
- `sales_orders` + `sales_order_items`（销售订单）
- `sales_outstocks` + `sales_outstock_items`（销售出库，含收货人/业务员信息）
- `sales_returns` + `sales_return_items`（销售退货）

### 仓储管理（6张表）
- `transfers` + `transfer_items`（调拨单）
- `check_orders` + `check_items`（盘点单）
- `loss_orders` + `loss_items`（报损报溢单）

### 财务表
| 表名 | 说明 |
|------|------|
| `receipts` | 收款记录（关联客户+销售订单） |
| `payments` | 付款记录（关联供应商+采购订单） |

---

## 权限系统（RBAC）

### 6个预设角色及权限范围

| 角色 | 权限范围 |
|------|----------|
| `admin` | 超级管理员——全部50+权限 |
| `warehouse` | 仓库人员——库存管理 + 商品查看 |
| `purchase` | 采购人员——采购管理 + 商品/供应商查看 |
| `sales` | 销售人员——销售管理 + 商品/客户查看 + 销售报表 |
| `finance` | 财务人员——财务管理 + 报表查看 |
| `viewer` | 只读用户——仪表盘 + 所有查看权限 |

### 权限检查方式
- `roles.permissions` 字段存储 JSON 数组，如 `["product_view", "product_edit", ...]`
- 每个页面在 include `header.php` 时自动调用 `require_permission('xxx')`
- admin 角色拥有所有权限，无需逐一配置

---

## 关键技术实现

### 1. SPA 导航系统（main.js）
- 拦截侧边栏链接点击，通过 `X-Nav: 1` 请求头获取局部内容
- 使用 History API（pushState/popstate）支持浏览器前进后退
- 非SPA场景（CRUD操作等）已添加 `data-nav-ignore` 标记

### 2. 单据编号规则
- 格式：`{前缀}{YYYYMMDD}{4位随机数}`
- 示例：`PO202607060001`（采购订单）
- `generate_bill_no($prefix)` 函数实现，保证当日唯一

### 3. 库存更新机制
- `inventory` 表使用 `INSERT ... ON DUPLICATE KEY UPDATE`（UPSERT）
- 每次库存变动自动写入 `inventory_logs`
- 撤回/反审核操作自动反向调整库存

### 4. Excel 导入导出
- 文件：`includes/xlsx_helper.php`
- 纯 PHP 实现（ZipArchive + SimpleXML），无外部依赖
- 支持 inlineStr 和共享字符串两种 Excel 格式
- 临时文件使用 `uploads/tmp/` 目录

### 5. 数据库备份恢复
- 文件：`modules/system/backup.php`
- 逐表导出 SQL，存储到 `backups/` 目录
- 支持上传 SQL 文件恢复

### 6. 前端技术栈
- Chart.js 4.4.0（销售趋势图、账龄分析图）
- Font Awesome 6.4.0（图标）
- 原生 JavaScript（无 jQuery）
- CSS Variables 主题系统

---

## 数据库配置

文件：`config/database.php`

```php
DB_HOST: localhost
DB_NAME: jinxiaocun
DB_USER: root
DB_PASS: (空)
DB_CHARSET: utf8mb4
DB_PORT: 3306
SITE_NAME: 鑫瓒进销存管理系统
ITEMS_PER_PAGE: 20
```

- `getDB()` 返回 PDO 单例（异常模式 + 关联数组获取）
- 安装后生成 `config/installed.lock` 防重复安装

---

## 核心公共函数（includes/functions.php）

| 函数 | 功能 |
|------|------|
| `safe_input()` | 安全过滤输入 |
| `json_response()` | 统一 JSON 响应格式 |
| `redirect()` | 页面重定向（支持 JS 降级） |
| `generate_bill_no($prefix)` | 生成唯一单据编号 |
| `get_paginated_data()` | 通用分页查询 |
| `add_log()` | 记录操作日志 |
| `get_stock()` | 获取商品库存 |
| `update_inventory()` | 更新库存（UPSERT + 记录日志） |
| `export_csv()` | 导出 CSV |
| `upload_file()` | 文件上传 |
| `build_items_html()` | 智能构建打印模板明细行 |
| `format_money()` | 格式化金额 |
| `get_options()` | 获取下拉选项 |
| `get_menu()` | 构建系统菜单 |

---

## 已知问题与修复记录

### 注意：JSON 接口文件不要 include header.php
`product_images.php` 等纯 JSON 接口必须 include `auth.php`（仅认证+DB连接），不能 include `header.php`（会输出完整HTML），否则 AJAX 收到的不是 JSON。

### 导出/下载类请求必须在 header.php 之前处理
所有需要输出文件/二进制内容的请求（如 `?export=xlsx`、模板下载），检测和处理代码必须放在 `include header.php` 之前，否则 headers 已发送导致失败。

### 图片路径问题
存储在 DB 的图片路径是 `uploads/products/{id}/xxx.jpg`，在 `/modules/` 子目录下的页面引用时需要加 `../../` 前缀。

### 弹窗的 inline style 优先问题
模态框默认隐藏应使用 CSS class 控制，不要使用 `style="display:none"`（inline style 优先级高于 class）。

### 数据库备份的 fetch 模式
`SHOW CREATE TABLE` 返回数字索引数组，必须使用 `fetch(PDO::FETCH_NUM)` 而非默认的关联数组。

### xlsx 临时文件
不要使用 `tempnam()` + `@unlink()` 模式创建临时文件，改用 `uploads/tmp/` 目录 + `uniqid()`。

---

## 最近更新（2026-07-06）

1. **Excel 导入导出**：新增 `xlsx_helper.php` 和 `import.php`，支持商品/客户/供应商批量导入导出
2. **首页工作台增强**：待审批单据、今日经营概况、应收款到期提醒
3. **商品图片管理**：多图上传、列表缩略图、点击放大预览
4. **数据库备份恢复**：手动备份、历史管理、上传恢复
5. **操作撤销/反审核**：出库单和入库单支持撤销，自动恢复库存
6. **多轮 Bug 修复**：导出错位、弹窗卡死、图片路径、页面滚动、模板下载等
