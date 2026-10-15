<?php
/**
 * 客户搜索：统一口径 + 命中片段显示
 *
 * 为什么要抽出来：客户资料的搜索（列表页 / 加载更多接口）、导出、公海、跟进记录
 * 是四个入口，各写一份 LIKE 条件的话，加一个搜索字段就要改四处，漏一处就会出现
 * 「列表里搜得到、导出搜不到」这种最难查的问题。这里定一份，四处都用。
 *
 * 搜索字段：名称 / 电话 / 公司 / 意向产品 / 备注 / 地址
 * 列表为了防旁边的人一眼看到名单，电话、公司、地址都不显示成列，
 * 但搜索命中时，命中的那个字段会以「片段 + 关键词高亮」的形式临时挂在客户名称下面
 * ——搜哪个字段就只补显示哪个字段，电话打码。
 */

if (!function_exists('crm_search_cond')) {
    /**
     * 客户搜索的 WHERE 条件
     *
     * 返回 cond 不含 AND、也不含外层括号，调用方自己包：
     *   $s = crm_search_cond($search, 'c');
     *   $where .= " AND ({$s['cond']} OR f.content LIKE ?)";   // 跟进记录页要多搜跟进内容
     *
     * @param string $kw    搜索关键词（原始值，不带 %）
     * @param string $alias 客户表别名
     * @return array{cond:string,params:array}
     */
    function crm_search_cond($kw, $alias = 'c')
    {
        $kw = trim((string)$kw);
        if ($kw === '') return ['cond' => '', 'params' => []];

        $like   = '%' . $kw . '%';
        $fields = ['name', 'phone', 'company', 'intended_product', 'remark', 'address'];
        $parts  = [];
        $params = [];
        foreach ($fields as $f) {
            $parts[]  = "IFNULL({$alias}.{$f},'') LIKE ?";
            $params[] = $like;
        }
        return ['cond' => implode(' OR ', $parts), 'params' => $params];
    }
}

if (!function_exists('crm_search_highlight')) {
    /**
     * 转义输出文本，并把命中的关键词包成 <mark class="kw-hit">
     *
     * 先按关键词切分、再逐段转义，不做「转义后整串替换」——后者遇到关键词里
     * 带 & < > 时会替换到转义实体内部，破坏 HTML。
     */
    function crm_search_highlight($text, $kw)
    {
        $text = (string)$text;
        if ($text === '') return '';
        $kw = trim((string)$kw);
        if ($kw === '') return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        $parts = @preg_split('/(' . preg_quote($kw, '/') . ')/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false || $parts === null) return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        $out = '';
        foreach ($parts as $i => $p) {
            if ($i % 2 === 1) {
                $out .= '<mark class="kw-hit">' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</mark>';
            } else {
                $out .= htmlspecialchars($p, ENT_QUOTES, 'UTF-8');
            }
        }
        return $out;
    }
}

if (!function_exists('crm_search_snippet')) {
    /**
     * 取命中位置前后的片段（默认前后各 8 个字），命中处高亮，两端用 … 表示截断
     * 没命中就返回 ''（调用方据此决定要不要显示这一行）
     *
     * 只截片段不整条显示：地址、备注动辄几十上百字，整条挂出来会把行撑得很高，
     * 片段 16~20 个字足够确认「是不是找对了这个人」。
     */
    function crm_search_snippet($value, $kw, $radius = 8)
    {
        $value = trim((string)$value);
        $kw    = trim((string)$kw);
        if ($value === '' || $kw === '') return '';

        $pos = mb_stripos($value, $kw);
        if ($pos === false) return '';

        $len   = mb_strlen($value);
        $kwLen = mb_strlen($kw);
        $start = max(0, $pos - $radius);
        $end   = min($len, $pos + $kwLen + $radius);

        $snip = mb_substr($value, $start, $end - $start);
        return ($start > 0 ? '…' : '') . crm_search_highlight($snip, $kw) . ($end < $len ? '…' : '');
    }
}

if (!function_exists('crm_mask_phone')) {
    /**
     * 电话打码：138****6688
     *
     * 搜索命中电话时必须打码——列表不显示电话列就是为了防旁边的人看到名单，
     * 搜索结果同样是屏幕上的名单，不能因为「是搜出来的」就把完整号码露出来。
     */
    function crm_mask_phone($phone)
    {
        $p = trim((string)$phone);
        if ($p === '') return '';
        $len = mb_strlen($p);
        if ($len >= 11) return mb_substr($p, 0, 3) . '****' . mb_substr($p, -4);   // 手机号
        if ($len >= 7)  return mb_substr($p, 0, 3) . '****' . mb_substr($p, -2);   // 固话
        if ($len >= 4)  return mb_substr($p, 0, 1) . '****' . mb_substr($p, -1);   // 短号
        return $p;
    }
}

if (!function_exists('crm_search_hits_html')) {
    /**
     * 客户行「客户名称」下方要补显示的命中片段
     *
     * 只补列表里看不到、但确实命中了搜索词的字段；客户名称本身就在列上，
     * 命中时由调用方直接高亮名称，不在这里重复挂一行。
     *
     * @param array  $item 一行客户数据
     * @param string $kw   搜索关键词
     * @return string HTML（无命中返回空串）
     */
    function crm_search_hits_html($item, $kw)
    {
        $kw = trim((string)$kw);
        if ($kw === '') return '';

        // 字段 => [显示名, 是否打码]
        $map = [
            'phone'            => ['电话',     true],
            'company'          => ['公司',     false],
            'address'          => ['地址',     false],
            'intended_product' => ['意向产品', false],
            'remark'           => ['备注',     false],
        ];

        $lines = [];
        foreach ($map as $field => $info) {
            $label = $info[0];
            $mask  = $info[1];
            $raw   = trim((string)($item[$field] ?? ''));
            if ($raw === '') continue;
            if (mb_stripos($raw, $kw) === false) continue;

            $shown = $mask ? crm_mask_phone($raw) : $raw;
            $snip  = crm_search_snippet($shown, $kw);
            // 打码可能正好把关键词盖掉（搜的几位被 * 替换了），这时取不到片段，
            // 也要把打码后的内容显示出来，否则用户不知道这一行是电话命中的
            if ($snip === '') $snip = htmlspecialchars($shown, ENT_QUOTES, 'UTF-8');

            $lines[] = '<div class="cust-hit-line"><b>' . $label . '</b>' . $snip . '</div>';
        }

        if (!$lines) return '';
        return '<div class="cust-hits">' . implode('', $lines) . '</div>';
    }
}
