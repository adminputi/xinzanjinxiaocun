<?php
/**
 * 公共 CRUD 辅助库
 * 
 * 提供标准化的分页列表查询、单据 CRUD 操作等公共方法，
 * 减少各模块之间的代码重复。
 * 
 * 用法：在模块文件中 require_once 后直接调用。
 */

/**
 * 执行标准分页列表查询（返回数据和分页信息）
 * 
 * @param string $sql          SELECT 完整 SQL（用 ? 占位符参数化）
 * @param string $countSql     COUNT 完整 SQL
 * @param array  $params       查询参数数组
 * @param int    $page         当前页码
 * @param int    $perPage      每页条数
 * @return array ['list'=>[], 'total'=>0, 'pages'=>0, 'page'=>1]
 */
function crud_list($sql, $countSql, $params = [], $page = 1, $perPage = null) {
    $pdo = getDB();
    $perPage = $perPage ?: ITEMS_PER_PAGE;
    $offset = max(0, ($page - 1) * $perPage);
    
    // 查询总数
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();
    
    // 查询数据
    $dataParams = array_merge($params, [$offset, $perPage]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($dataParams);
    $list = $stmt->fetchAll();
    
    return [
        'list'  => $list,
        'total' => $total,
        'pages' => max(1, ceil($total / $perPage)),
        'page'  => $page,
    ];
}

/**
 * 安全获取 GET/POST 参数（带默认值）
 */
function get_param($key, $default = '') {
    return $_GET[$key] ?? $default;
}
function post_param($key, $default = '') {
    return $_POST[$key] ?? $default;
}

/**
 * 获取分页查询用的页码和搜索关键词
 */
function get_list_params() {
    return [
        'page'   => max(1, intval($_GET['page'] ?? 1)),
        'search' => trim($_GET['search'] ?? ''),
    ];
}

/**
 * 输出分页导航 HTML
 */
function pagination_html($page, $total, $pages, $extraParams = '') {
    if ($pages <= 1) return '';
    $html = '<div class="pagination">';
    $html .= '<span class="info">共' . $total . '条/' . $pages . '页</span>';
    for ($i = 1; $i <= $pages; $i++) {
        $active = $i == $page ? ' active' : '';
        $html .= '<a href="?page=' . $i . $extraParams . '" class="' . $active . '">' . $i . '</a>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * 统一错误处理：记录错误并设置用户可见的错误消息
 */
function handle_error($message, $context = '') {
    error_log('App Error' . ($context ? " [$context]" : '') . ': ' . $message);
    return $message;
}

/**
 * 数据库事务封装
 */
function transaction($callback) {
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $result = $callback($pdo);
        $pdo->commit();
        return $result;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Transaction failed: ' . $e->getMessage());
        throw $e;
    }
}
