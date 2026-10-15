<?php
/**
 * 销售单据列表：业务员 / 状态筛选
 *
 * 报价、合同、订单、出库四个列表都要「搜业务员 + 按业务员筛 + 按状态筛」，
 * 逻辑一样，抽一份共用：以后加筛选条件只改这里。
 *
 * 两个口径说明：
 * 1. 单据里存的是 employee_id（users.id），不是业务员名字。所以「搜业务员」
 *    必须 JOIN users 才能按 real_name 匹配——四个列表的 COUNT 查询原本都没
 *    JOIN users，调用本文件的条件前要记得给 COUNT 也补上 JOIN，否则会报
 *    Unknown column 'u.real_name'（列表查询本来就有 JOIN，只漏了 COUNT）。
 * 2. 出库单特殊：除了 employee_id，还有 salesperson_name 这个直接存名字的
 *    文本字段（列表里显示的就是它），历史单据存在 employee_id=0 但名字有值
 *    的情况，所以出库单两边都认，避免筛出来漏单。
 */

if (!function_exists('sales_emp_options')) {
    /**
     * 业务员下拉选项：只列「在这张单据表里实际出现过」的业务员
     *
     * 不列整个 users 表：离职的、从没开过单的人占一屏，选了也必定是空结果。
     *
     * @param PDO    $pdo
     * @param string $table   单据表名
     * @param string $idCol   业务员字段
     * @param string $nameCol 可选：直接存名字的文本字段（出库单的 salesperson_name）
     * @return array 业务员名字列表（已去重、已排序）
     */
    function sales_emp_options($pdo, $table, $idCol = 'employee_id', $nameCol = null)
    {
        $names = [];

        $st = $pdo->query("SELECT DISTINCT u.real_name
                           FROM `$table` t
                           JOIN users u ON u.id = t.$idCol
                           WHERE t.$idCol > 0");
        if ($st) {
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) {
                if (trim((string)$n) !== '') $names[trim((string)$n)] = true;
            }
        }

        if ($nameCol) {
            $st = $pdo->query("SELECT DISTINCT t.$nameCol
                               FROM `$table` t
                               WHERE IFNULL(t.$nameCol,'') <> ''");
            if ($st) {
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) {
                    if (trim((string)$n) !== '') $names[trim((string)$n)] = true;
                }
            }
        }

        $names = array_keys($names);
        sort($names, SORT_STRING);
        return $names;
    }
}

if (!function_exists('sales_emp_cond')) {
    /**
     * 按业务员名字筛单据的 WHERE 条件（不含 AND，调用方自己包）
     *
     * 传进来的是名字，要反查 users.id 再去匹配 employee_id；
     * 出库单额外把 salesperson_name 也并上。
     *
     * @return array{cond:string,params:array}
     */
    function sales_emp_cond($pdo, $emp, $alias, $idCol = 'employee_id', $nameCol = null)
    {
        $emp = trim((string)$emp);
        if ($emp === '') return ['cond' => '', 'params' => []];

        $eid = 0;
        $st  = $pdo->prepare("SELECT id FROM users WHERE real_name=? LIMIT 1");
        $st->execute([$emp]);
        $eid = intval($st->fetchColumn());

        $parts  = [];
        $params = [];
        if ($eid > 0) { $parts[] = "$alias.$idCol = ?"; $params[] = $eid; }
        if ($nameCol) { $parts[] = "$alias.$nameCol = ?"; $params[] = $emp; }

        // 名字既不在 users 里、也没存成文本 → 注定查不到，直接给个不成立的条件
        if (!$parts) return ['cond' => '1=0', 'params' => []];

        return ['cond' => '(' . implode(' OR ', $parts) . ')', 'params' => $params];
    }
}

if (!function_exists('sales_page_query')) {
    /**
     * 分页链接的查询串：保留当前所有筛选条件，只替换 page
     *
     * 原来订单、出库的分页写死成 ?page=N，一翻页搜索词就丢了；
     * 报价、合同只带了 search，加了业务员筛选后同样会丢。这里统一处理。
     *
     * @param int   $page
     * @param array $keep 要保留的参数名（其余忽略）
     * @return string 形如 "search=abc&employee=%E5%BC%A0%E4%B8%89&page=2"
     */
    function sales_page_query($page, $keep = [])
    {
        $out = [];
        foreach ($keep as $k) {
            $v = $_GET[$k] ?? '';
            if ($v === '' || $v === null) continue;
            if (is_array($v)) continue;
            $out[$k] = $v;
        }
        $out['page'] = $page;
        return htmlspecialchars(http_build_query($out), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sales_status_select')) {
    /**
     * 状态筛选下拉：选项来自各页自己的 $statusLabels（值 => 中文），
     * 保证下拉里写的和列表里显示的是同一套说法。
     *
     * @param array  $labels   ['draft'=>'草稿', ...]
     * @param string $current  当前选中的值
     * @return string HTML
     */
    function sales_status_select($labels, $current = '')
    {
        $html = '<select name="status" class="form-control" style="width:auto;" onchange="this.form.submit()">'
              . '<option value="">全部状态</option>';
        foreach ($labels as $val => $label) {
            $sel = ($current === (string)$val) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>'
                   . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $html .= '</select>';
        return $html;
    }
}

if (!function_exists('sales_emp_select')) {
    /**
     * 业务员筛选下拉：值就是业务员名字（出库单存在文本名没有 users.id 的情况，
     * 用 id 当值会漏掉历史单据，统一用名字最稳）
     */
    function sales_emp_select($options, $current = '')
    {
        $html = '<select name="employee" class="form-control" style="width:auto;" onchange="this.form.submit()">'
              . '<option value="">全部业务员</option>';
        foreach ($options as $name) {
            $sel = ($current === (string)$name) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>'
                   . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $html .= '</select>';
        return $html;
    }
}
