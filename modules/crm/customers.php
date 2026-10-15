<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('crm_customer_view');
$pdo = getDB();
require_once __DIR__ . '/../../includes/migration.php';
run_migrations();
$isAdmin = (get_user_role() === 'admin');
$userId = get_user_id();

$page = max(1, intval($_GET['page'] ?? 1));   // 只用于兼容老链接里的 ?page=3：现在列表是「加载更多」，不再翻页
require_once __DIR__ . '/customer_list_partial.php';

// 每页条数（同时也是每次点「加载更多」增加的条数）：可手填，默认 50，上限 500
// 上次选的值记在 cookie 里（cookie 由 JS 写入 —— 这里 PHP 已经输出过内容，不能再 setcookie）
$perPageCookie = intval($_COOKIE['crm_cust_per_page'] ?? 0);
$perPage       = intval($_GET['per_page'] ?? 0);
if ($perPage <= 0) { $perPage = $perPageCookie > 0 ? $perPageCookie : CRM_CUSTOMER_PAGE_DEFAULT; }
$perPage = max(CRM_CUSTOMER_PAGE_MIN, min(CRM_CUSTOMER_PAGE_MAX, $perPage));

// 取数和行渲染都交给公共片段：首屏和「加载更多」接口共用同一份，
// 否则以后改了列表（列宽、按钮、跟进内容），接口返回的还是旧样子，两边必然对不上
$q      = crm_customer_query($pdo, $userId, $isAdmin, $_GET, 1, $perPage);
$list   = $q['list'];
$total  = $q['total'];
$pages  = $q['pages'];
$search        = $q['filters']['search'];
$sourceId      = $q['filters']['source_id'];
$ownerId       = $q['filters']['owner_id'];
$intention     = $q['filters']['intention'];
$developedFrom = $q['filters']['developed_from'];
$developedTo   = $q['filters']['developed_to'];
$followFrom    = $q['filters']['follow_from'];
$followTo      = $q['filters']['follow_to'];
$deal          = $q['filters']['deal'];
$sort          = $q['filters']['sort'];
$flag          = $q['filters']['flag'];
$showPool      = $q['filters']['pool'];

// 「加载更多」请求时要带上的筛选条件（不含 page，页码由 JS 自己累加）
// 注意不能用 array_filter 的默认回调：它会把 deal=0（未成交）也当空值丢掉
$ajaxQuery = http_build_query(array_filter([
    'search' => $search, 'source_id' => $sourceId, 'owner_id' => $ownerId,
    'intention' => $intention, 'developed_from' => $developedFrom, 'developed_to' => $developedTo,
    'follow_from' => $followFrom, 'follow_to' => $followTo, 'deal' => $deal,
    'sort' => $sort, 'flag' => $flag, 'pool' => $showPool,
], function ($v) { return $v !== '' && $v !== null && $v !== 0; }));
// 筛选条件指纹：条件变了就不该再恢复上次的浏览位置
$listSig = md5($ajaxQuery . '|' . $userId);
// 上面那一大段取数（筛选 / 排序 / 置顶标红 JOIN / 最新跟进）已全部移进
// customer_list_partial.php，这里只管用 —— 接口侧取第 2、3…页时走的是同一份代码

$sources = $pdo->query("SELECT * FROM customer_sources WHERE status=1 ORDER BY sort_order")->fetchAll();
$owners = $pdo->query("SELECT id, real_name FROM users WHERE status=1 ORDER BY real_name")->fetchAll();
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-users"></i> 客户资料</h1>
    <button class="btn btn-primary" onclick="openCustomerForm()"><i class="fa-solid fa-plus"></i> 新增客户</button>
</div>

<form class="filter-bar" method="get" style="flex-wrap:wrap;">
    <div class="search-box"><i class="fa-solid fa-search"></i><input type="text" name="search" class="form-control" placeholder="搜索名称/电话/地址等..." value="<?=htmlspecialchars($search)?>"></div>
    <select name="source_id" class="form-control" style="width:120px;">
        <option value="0">全部来源</option>
        <?php foreach($sources as $s): ?><option value="<?=$s['id']?>" <?=$sourceId==$s['id']?'selected':''?>><?=htmlspecialchars($s['name'])?></option><?php endforeach; ?>
    </select>
    <?php if ($isAdmin): ?>
    <select name="owner_id" class="form-control" style="width:120px;">
        <option value="0">全部经理</option>
        <?php foreach($owners as $o): ?><option value="<?=$o['id']?>" <?=$ownerId==$o['id']?'selected':''?>><?=htmlspecialchars($o['real_name'])?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select name="intention" class="form-control" style="width:100px;">
        <option value="">全部意向</option>
        <option value="高" <?=$intention=='高'?'selected':''?>>高</option>
        <option value="中" <?=$intention=='中'?'selected':''?>>中</option>
        <option value="低" <?=$intention=='低'?'selected':''?>>低</option>
    </select>
    <select name="deal" class="form-control" style="width:110px;">
        <option value="">全部成交</option>
        <option value="1" <?=$deal==='1'?'selected':''?>>已成交</option>
        <option value="0" <?=$deal==='0'?'selected':''?>>未成交</option>
    </select>
    <select name="sort" class="form-control" style="width:150px;">
        <option value="">默认排序（置顶优先）</option>
        <option value="deal_amount" <?=$sort==='deal_amount'?'selected':''?>>按成交金额</option>
        <option value="last_deal" <?=$sort==='last_deal'?'selected':''?>>按最近成交</option>
        <option value="last_follow" <?=$sort==='last_follow'?'selected':''?>>按最近跟进</option>
    </select>
    <select name="flag" class="form-control" style="width:120px;">
        <option value="">全部标记</option>
        <option value="pin" <?=$flag==='pin'?'selected':''?>>只看置顶</option>
        <option value="mark" <?=$flag==='mark'?'selected':''?>>只看标红</option>
    </select>
    <?php // 每页条数：既是首屏显示的条数，也是每次点「加载更多」增加的条数。
          // 选过的值由 setPerPage() 写进 cookie，下次进页面还是它，不用每次改 ?>
    <select name="per_page" class="form-control" style="width:120px;" onchange="setPerPage(this)"
            title="首屏显示的条数，也是每次点「加载更多」增加的条数（<?=CRM_CUSTOMER_PAGE_MIN?>~<?=CRM_CUSTOMER_PAGE_MAX?>）">
        <?php foreach ([20, 30, 50, 100, 200, 500] as $n): ?>
        <option value="<?=$n?>" <?=$perPage===$n?'selected':''?>>每页 <?=$n?> 条</option>
        <?php endforeach; ?>
        <?php if (!in_array($perPage, [20, 30, 50, 100, 200, 500], true)): ?>
        <option value="<?=$perPage?>" selected>每页 <?=$perPage?> 条</option>
        <?php endif; ?>
    </select>
    <div style="display:flex;align-items:center;gap:4px;flex-wrap:wrap;">
        <span style="font-size:13px;color:var(--gray-600);">开发日期</span>
        <input type="date" name="developed_from" class="form-control" style="width:130px;" value="<?=htmlspecialchars($developedFrom)?>">
        <span style="color:var(--gray-500);">~</span>
        <input type="date" name="developed_to" class="form-control" style="width:130px;" value="<?=htmlspecialchars($developedTo)?>">
        <span style="font-size:13px;color:var(--gray-600);margin-left:8px;">跟进日期</span>
        <input type="date" name="follow_from" class="form-control" style="width:130px;" value="<?=htmlspecialchars($followFrom)?>">
        <span style="color:var(--gray-500);">~</span>
        <input type="date" name="follow_to" class="form-control" style="width:130px;" value="<?=htmlspecialchars($followTo)?>">
        <button type="submit" class="btn btn-primary btn-sm">查询</button>
        <a href="customers.php" class="btn btn-outline btn-sm">清除</a>
    </div>
</form>

<div class="card"><div class="card-body" style="padding:0;">
<?php $flagCounts = customer_flag_counts($userId); ?>
<?php // 最近跟进这一列的内容要不要显示，由「CRM跟进查看」权限决定（Q2） ?>
<?php $canViewFollowup = check_permission('crm_followup_view'); ?>
<div style="padding:8px 16px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
    <span style="font-size:12px;color:var(--gray-500);">
        <i class="fa-solid fa-thumbtack" style="color:var(--primary);"></i> 已置顶 <strong id="pinnedCount"><?=$flagCounts['pinned']?></strong> 个，
        <i class="fa-solid fa-flag" style="color:var(--danger);"></i> 已标红 <strong id="markedCount"><?=$flagCounts['marked']?></strong> 个
        <span style="color:var(--gray-400);">（置顶/标红只对你自己生效，不影响其他人的列表顺序）</span>
    </span>
    <span id="checkedInfo" style="font-size:12px;color:var(--primary);font-weight:600;"></span>
    <span style="flex:1;"></span>
    <button class="btn btn-sm btn-outline" onclick="batchFlag('pin',1)"><i class="fa-solid fa-thumbtack"></i> 批量置顶</button>
    <button class="btn btn-sm btn-outline" onclick="batchFlag('pin',0)"><i class="fa-solid fa-thumbtack fa-rotate-180"></i> 取消置顶</button>
    <button class="btn btn-sm btn-outline" onclick="batchFlag('mark',1)" style="color:var(--danger);"><i class="fa-solid fa-flag"></i> 批量标红</button>
    <button class="btn btn-sm btn-outline" onclick="batchFlag('mark',0)"><i class="fa-regular fa-flag"></i> 取消标红</button>
    <?php // 批量删除原先只认主数据的 customer_edit，与本页新增/编辑用的 crm_customer_edit 不是一套。
          // 统一为「两者有其一即可」：既对齐语义，也不会让原本有权限的人突然删不动 ?>
    <?php if (check_permission('crm_customer_edit') || check_permission('customer_edit')): ?>
    <button class="btn btn-sm btn-danger" onclick="batchDelete()"><i class="fa-solid fa-trash"></i> 批量删除</button>
    <?php endif; ?>
    <a href="export.php?<?=http_build_query($_GET)?>" class="btn btn-sm btn-outline"><i class="fa-solid fa-file-excel"></i> 导出Excel</a>
</div>
<?php // 滑动窗口折叠提示：列表加载到 600 行后会把上面看过的行卸掉，保住流畅度。
      // 卸掉的行不在 DOM 里，Ctrl+F 搜不到，所以这里明说「找人请用搜索框」 ?>
<div id="foldBar" style="display:none;padding:6px 16px;background:#fffbeb;border-bottom:1px solid #fde68a;
     font-size:12px;color:#92400e;align-items:center;gap:10px;flex-wrap:wrap;">
    <i class="fa-solid fa-compress"></i><span id="foldText"></span>
    <button type="button" class="btn btn-sm btn-outline" onclick="backToStart()"><i class="fa-solid fa-arrow-up"></i> 回到开头</button>
    <span style="color:#b45309;">（上面的客户已被折叠以保流畅，找人请用上方搜索框）</span>
</div>
<div class="table-container">
<?php // 列宽锁死：table-layout:fixed + colgroup，表格宽度只认这里配的比例。
      // 默认的 auto 布局会按内容多少分配宽度，跟进内容一长就把整张表横向撑开，
      // 出现横向滚动条；fixed 布局下超出的内容只能换行消化，宽度不再受内容影响 ?>
<table style="table-layout:fixed;width:100%;">
<colgroup>
    <col style="width:40px;">
    <col style="width:12%;">
    <col style="width:32%;">
    <col style="width:6%;">
    <col style="width:5%;">
    <col style="width:8%;">
    <col style="width:7%;">
    <col style="width:5%;">
    <col style="width:8%;">
    <col style="width:17%;">
</colgroup>
<thead><tr>
    <th><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
    <?php // 电话/公司列已隐藏：防止名单被旁边的人一眼看到（仍是可通过搜索框搜索到的）
          // 原位置换成「最近跟进」；「最后跟进」时间列也去掉了——它和最近跟进里的日期重复，
          // 删掉给跟进内容腾宽度。按跟进日期的筛选、以及「按最近跟进」排序都还在（不依赖列显示） ?>
    <th>客户名称</th><th>最近跟进</th><th>来源</th><th>意向</th><th>意向产品</th><th>归属</th><th>跟进</th><th>开发日期</th><th>操作</th>
</tr></thead>
<?php // 行渲染也在公共片段里：首屏和「加载更多」返回的是同一个模板，
      // 后面改列的样式、按钮、跟进内容，两边一起变，不会出现接口返回旧样子 ?>
<tbody id="custTbody">
<?php // 第 5 个参数是搜索词：命中字段的片段显示、关键词高亮都靠它 ?>
<?php crm_render_customer_rows($list, $userId, $isAdmin, $canViewFollowup, $search); ?>
<?php if (!$list): ?>
<tr><td colspan="10"><div class="empty-state"><i class="fa-solid fa-users"></i><p>暂无客户数据</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<style>
/* 跟进内容：最多两行，内容少就自然只占一行（不按字数截断，能多显示就多显示） */
.follow-brief { min-width: 0; max-width: 100%; }
.follow-brief-text {
    flex: 1 1 auto; min-width: 0;         /* 允许在固定列宽内被压窄，绝不撑宽单元格 */
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;
    overflow:hidden; line-height:1.5; max-height:3em;
    word-break:break-all; overflow-wrap:anywhere;   /* 中英文混排都能断行，不留横向溢出 */
}
/* 标红 / 已成交的行底色：写成 class（原来是内联 style）是为了让 JS 能直接增删 class
   切换标红，不必整页刷新——刷新会把已经加载出来的几页全冲掉 */
tr.cust-marked { background:#fff1f2; }
tr.cust-deal:not(.cust-marked) { background:#f0fdf4; }
.cust-name { color:var(--primary); font-weight:500; }
tr.cust-marked .cust-name { color:var(--danger); font-weight:600; }
/* 搜索命中的字段补显示在客户名称下方：列表平时不显示电话/公司/地址这些列
   （防旁边的人一眼看到名单），只有搜索命中时才临时挂出来，且只挂命中的那个字段。
   片段最长约 20 个字，不按整条地址显示，避免把行撑得很高 */
.cust-hits { margin-top:3px; }
.cust-hit-line {
    font-size:12px; color:var(--gray-500); line-height:1.5;
    word-break:break-all; overflow-wrap:anywhere;   /* 长地址靠换行消化，不撑宽单元格 */
}
.cust-hit-line b { color:var(--gray-600); font-weight:600; margin-right:4px; }
mark.kw-hit { background:#fef9c3; color:#92400e; padding:0 1px; border-radius:2px; }
</style>

<!-- 最近跟进全文浮层：跟随鼠标，内容长可滚动；延迟 0.3 秒弹出，避免扫过时乱飘 -->
<div id="followTip" style="display:none;position:fixed;z-index:9999;max-width:440px;max-height:320px;overflow:auto;
    background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;font-size:13px;line-height:1.6;
    white-space:pre-wrap;word-break:break-all;box-shadow:0 6px 20px rgba(0,0,0,.15);pointer-events:none;"></div>

<?php // 不再翻页：底部一个「加载更多」，点一次无刷新追加一页，滑到底继续点。
      // 列表超过 600 行后会把上面看过的行折叠掉（见 maybeFold），所以长期用也不会越点越卡 ?>
<div id="loadMoreWrap" style="text-align:center;padding:16px 12px 24px;">
    <div id="loadMoreInfo" style="font-size:13px;color:var(--gray-500);margin-bottom:10px;"></div>
    <button type="button" id="loadMoreBtn" class="btn btn-outline" onclick="loadMoreCustomers()">
        <i class="fa-solid fa-chevron-down"></i> 加载更多
    </button>
</div>
<div id="backTopBtn" onclick="window.scrollTo({top:0,behavior:'smooth'})" title="回到顶部"
     style="display:none;position:fixed;right:24px;bottom:32px;z-index:900;width:40px;height:40px;
            border-radius:50%;background:#fff;border:1px solid #e2e8f0;box-shadow:0 2px 10px rgba(0,0,0,.12);
            cursor:pointer;align-items:center;justify-content:center;color:var(--gray-600);">
    <i class="fa-solid fa-arrow-up"></i>
</div>

<!-- 新增/编辑客户弹窗 -->
<div class="modal-overlay" id="customerModal"><div class="modal modal-lg"><div class="modal-header"><h3 class="modal-title" id="custModalTitle">新增客户</h3><button class="modal-close" onclick="closeModal('customerModal')">&times;</button></div>
<form id="customerForm" onsubmit="return saveCustomer(event)">
<?=csrf_field()?>
<input type="hidden" name="id" id="custId" value="0">
<div class="modal-body">
    <div class="form-row">
        <div class="form-group"><label class="form-label">客户名称 <span class="required">*</span></label><input type="text" name="name" id="custName" class="form-control" required></div>
        <div class="form-group"><label class="form-label">客户类型</label>
            <select name="type" id="custType" class="form-control">
                <option value="company">企业</option><option value="individual">个人</option>
            </select>
        </div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">客户编码</label><input type="text" name="code" id="custCode" class="form-control"><small style="color:var(--gray-500);">留空自动生成</small></div>
        <div class="form-group"><label class="form-label">期初应收</label><input type="number" step="0.01" name="initial_balance" id="custInitialBalance" class="form-control" value="0"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">联系人</label><input type="text" name="contact" id="custContact" class="form-control"></div>
        <div class="form-group"><label class="form-label">电话</label><input type="text" name="phone" id="custPhone" class="form-control" onblur="checkPhone()">
            <small id="phoneDup" style="color:var(--danger);display:none;"></small>
        </div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">公司名称</label><input type="text" name="company" id="custCompany" class="form-control"></div>
        <div class="form-group"><label class="form-label">微信号</label><input type="text" name="wechat" id="custWechat" class="form-control"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">邮箱</label><input type="text" name="email" id="custEmail" class="form-control"></div>
        <div class="form-group"><label class="form-label">来源</label>
            <select name="source_id" id="custSource" class="form-control">
                <option value="">请选择</option>
                <?php foreach($sources as $s): ?><option value="<?=$s['id']?>"><?=htmlspecialchars($s['name'])?></option><?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">意向程度</label>
            <select name="intention" id="custIntention" class="form-control">
                <option value="">请选择</option><option value="高">高</option><option value="中">中</option><option value="低">低</option>
            </select>
        </div>
        <div class="form-group"><label class="form-label">意向产品</label><input type="text" name="intended_product" id="custIntendedProduct" class="form-control" placeholder="例如：服务器/云服务/软件"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">地址</label><input type="text" name="address" id="custAddress" class="form-control"></div>
        <div class="form-group"><label class="form-label">开发日期</label><input type="date" name="developed_at" id="custDevelopedAt" class="form-control" value="<?=date('Y-m-d')?>"></div>
    </div>
    <div class="form-group"><label class="form-label">备注</label><textarea name="remark" id="custRemark" class="form-control" rows="2"></textarea></div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-outline" onclick="closeModal('customerModal')">取消</button>
    <button type="submit" class="btn btn-primary">保存</button>
</div>
</form></div></div>

<!-- 跟进弹窗 -->
<div class="modal-overlay" id="followupModal"><div class="modal modal-md"><div class="modal-header"><h3 class="modal-title">添加跟进 - <span id="fuCustomerName"></span></h3><button class="modal-close" onclick="closeModal('followupModal')">&times;</button></div>
<form id="followupForm" onsubmit="return saveFollowup(event)" enctype="multipart/form-data">
<?=csrf_field()?>
<input type="hidden" name="customer_id" id="fuCustomerId">
<div class="modal-body">
    <div class="form-row">
        <div class="form-group"><label class="form-label">跟进类型</label>
            <select name="follow_type" class="form-control">
                <option value="电话">电话</option><option value="微信">微信</option><option value="面谈">面谈</option><option value="拜访">拜访</option><option value="短信">短信</option><option value="邮件">邮件</option><option value="其他">其他</option>
            </select>
        </div>
        <div class="form-group"><label class="form-label">跟进结果</label>
            <select name="result" class="form-control">
                <option value="待跟进">待跟进</option><option value="有意向">有意向</option><option value="已成交">已成交</option><option value="无意向">无意向</option>
            </select>
        </div>
    </div>
    <div class="form-group"><label class="form-label">跟进内容 <span class="required">*</span></label>
        <textarea name="content" class="form-control" rows="4" required placeholder="记录跟进的具体内容..."></textarea>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">上传附件（合同等）</label>
            <input type="file" name="attachment" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.pdf,.zip,.rar">
            <small style="color:var(--gray-500);">支持 JPG/PNG/GIF/WEBP 图片、Word/Excel/PPT、PDF、RAR/ZIP，单个最大 50MB</small>
        </div>
        <div class="form-group"><label class="form-label">计划下次跟进</label>
            <input type="datetime-local" name="next_follow_at" class="form-control">
            <div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap;">
                <button type="button" class="btn btn-sm btn-outline" onclick="setNextFollowQuick(this.closest('.form-group').querySelector('[name=next_follow_at]'),1)">明天 09:00</button>
                <button type="button" class="btn btn-sm btn-outline" onclick="setNextFollowQuick(this.closest('.form-group').querySelector('[name=next_follow_at]'),7)">一周后 09:00</button>
                <button type="button" class="btn btn-sm btn-outline" onclick="setNextFollowQuick(this.closest('.form-group').querySelector('[name=next_follow_at]'),null)">清除</button>
            </div>
            <small style="color:var(--gray-500);">留空表示暂不计划下次跟进</small>
        </div>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-outline" onclick="closeModal('followupModal')">取消</button>
    <button type="submit" class="btn btn-primary">保存跟进</button>
</div>
</form></div></div>

<!-- 转移客户弹窗 -->
<div class="modal-overlay" id="transferModal"><div class="modal modal-sm"><div class="modal-header"><h3 class="modal-title">转移客户 - <span id="transferCustName"></span></h3><button class="modal-close" onclick="closeModal('transferModal')">&times;</button></div>
<form onsubmit="return doTransfer(event)">
<?=csrf_field()?>
<input type="hidden" id="transferCustId">
<div class="modal-body">
    <div class="form-group"><label class="form-label">转移给 <span class="required">*</span></label>
        <select id="transferToUser" class="form-control" required>
            <option value="">请选择业务经理</option>
            <?php foreach($owners as $u): if($u['id']==$userId) continue; ?>
            <option value="<?=$u['id']?>"><?=htmlspecialchars($u['real_name'])?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-outline" onclick="closeModal('transferModal')">取消</button>
    <button type="submit" class="btn btn-primary">确认转移</button>
</div>
</form></div></div>

<script>
var CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
var CURRENT_USER_ID = <?=$userId?>;
// 安全 fetch 封装：自动处理 CSRF 过期等问题
function safeFetch(url, options) {
    return fetch(url, options).then(function(r) {
        if (!r.ok) {
            return r.text().then(function(t) { throw new Error('请求失败 HTTP ' + r.status); });
        }
        return r.text().then(function(t) {
            try { return JSON.parse(t); }
            catch(e) { throw new Error('服务器返回异常，请刷新后重试'); }
        });
    });
}

function openCustomerForm(){
    document.getElementById('customerForm').reset();
    document.getElementById('custId').value=0;
    document.getElementById('custModalTitle').textContent='新增客户';
    document.getElementById('phoneDup').style.display='none';
    openModal('customerModal');
    document.getElementById('customerForm').action='customer_form_handler.php';
}
function editCustomer(id){
    safeFetch('ajax.php?action=get_customer&customer_id='+id)
    .then(function(resp){
        if(!resp.success){alert(resp.message);return;}
        var c=resp.data.customer;
        if(!c){alert('未找到客户数据');return;}
        document.getElementById('custId').value=c.id;
        document.getElementById('custName').value=c.name||'';
        document.getElementById('custType').value=c.type||'company';
        document.getElementById('custCode').value=c.code||'';
        document.getElementById('custInitialBalance').value=c.initial_balance||'0';
        document.getElementById('custContact').value=c.contact||'';
        document.getElementById('custPhone').value=c.phone||'';
        document.getElementById('custCompany').value=c.company||'';
        document.getElementById('custWechat').value=c.wechat||'';
        document.getElementById('custEmail').value=c.email||'';
        document.getElementById('custSource').value=c.source_id||'';
        document.getElementById('custIntention').value=c.intention||'';
        document.getElementById('custIntendedProduct').value=c.intended_product||'';
        document.getElementById('custAddress').value=c.address||'';
        document.getElementById('custDevelopedAt').value=c.developed_at||'';
        document.getElementById('custRemark').value=c.remark||'';
        document.getElementById('phoneDup').style.display='none';
        document.getElementById('custModalTitle').textContent='编辑客户';
        document.getElementById('customerForm').action='customer_form_handler.php';
        openModal('customerModal');
    }).catch(function(err){
        alert('加载客户数据失败：'+err.message);
    });
}
function checkPhone(){
    var phone=document.getElementById('custPhone').value.trim();
    var dup=document.getElementById('phoneDup');
    if(!phone){dup.style.display='none';return;}
    var id=document.getElementById('custId').value;
    safeFetch('ajax.php?action=check_phone&phone='+encodeURIComponent(phone))
    .then(function(resp){
        if(resp.success&&resp.data&&resp.data.length>0){
            var names=resp.data.map(function(d){return d.name+(d.owner_name?' (归属：'+d.owner_name+')':'')+(d.id==id?' \u2190当前':'');}).join(', ');
            dup.textContent='\u26a0 该电话已存在：'+names+'，确认仍要添加？';
            dup.style.display='block';
        }else{dup.style.display='none';}
    }).catch(function(err){console.error(err);});
}
function saveCustomer(e){
    e.preventDefault();
    var fd=new FormData(document.getElementById('customerForm'));
    var btn = document.querySelector('#customerModal button[type=submit]');
    if(btn){btn.disabled=true;btn.textContent='保存中...';}
    safeFetch('customer_form_handler.php',{method:'POST',body:fd})
    .then(function(resp){
        if(resp.success){
            closeModal('customerModal');
            alert(resp.message||'保存成功');
            location.reload();
        } else {
            alert(resp.message);
        }
    }).catch(function(err){
        alert('保存失败：'+err.message);
    }).finally(function(){
        if(btn){btn.disabled=false;btn.textContent='保存';}
    });
    return false;
}
function showFollowupModal(cid,cname){
    document.getElementById('fuCustomerId').value=cid;
    document.getElementById('fuCustomerName').textContent=cname;
    document.getElementById('followupForm').reset();
    openModal('followupModal');
}
function saveFollowup(e){
    e.preventDefault();
    var form=document.getElementById('followupForm');
    var fileInput=form.querySelector('input[type=file][name=attachment]');
    if(fileInput && fileInput.files && fileInput.files[0] && fileInput.files[0].size > 50*1024*1024){
        alert('附件大小超过 50MB 上限，请压缩后再上传');
        return false;
    }
    var fd=new FormData(form);
    fd.append('action','add_followup');
    var btn = document.querySelector('#followupModal button[type=submit]');
    if(btn){btn.disabled=true;btn.textContent='保存中...';}
    safeFetch('ajax.php',{method:'POST',body:fd})
    .then(function(resp){
        if(resp.success){
            closeModal('followupModal');
            alert(resp.message||'跟进已添加');
            location.reload();
        } else {
            alert(resp.message);
        }
    }).catch(function(err){
        alert('跟进保存失败：'+err.message);
    }).finally(function(){
        if(btn){btn.disabled=false;btn.textContent='保存跟进';}
    });
    return false;
}
// ===== 最近跟进列：悬停看全文、点击展开全部 =====
// 展开时才去服务端取该客户的全部跟进（列表本身不加载），并缓存结果，
// 收起再展开不会再请求一次；保存跟进后页面会刷新，缓存自然失效。
var followTip=null, followTipTimer=null, followCache={}, followMouseX=0, followMouseY=0;
function escText(s){
    return String((s===null||s===undefined)?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
document.addEventListener('DOMContentLoaded', function(){
    followTip = document.getElementById('followTip');
    if(!followTip) return;
    // 挂到 body 末尾：浮层不再受表格卡片、overflow 等祖先样式影响
    document.body.appendChild(followTip);
    bindFollowBriefs();   // 抽成函数：加载更多追加新行后要再调一次，否则新行没有悬停浮层
});
// 逐个单元格绑定（而不是在容器上监听 mouseover 冒泡）：
// 冒泡会在内部子元素之间反复触发并重置计时器，导致浮层迟迟不出现。
// 带 data-bound 标记，重复调用不会重复绑定
function bindFollowBriefs(){
    Array.prototype.forEach.call(document.querySelectorAll('.follow-brief'), function(el){
        if(el.getAttribute('data-bound')==='1') return;
        el.setAttribute('data-bound','1');
        var tip = el.getAttribute('data-tip') || '';
        if(!tip) return;
        el.addEventListener('mouseenter', function(e){
            followMouseX=e.clientX; followMouseY=e.clientY;
            clearTimeout(followTipTimer);
            // 停留 0.3 秒才弹，鼠标扫过时不会满屏浮块
            followTipTimer = setTimeout(function(){ showFollowTip(tip); }, 300);
        });
        el.addEventListener('mousemove', function(e){
            followMouseX=e.clientX; followMouseY=e.clientY;
            // 真正跟随鼠标：已经显示的话就跟着走
            if(followTip.style.display === 'block') positionFollowTip();
        });
        el.addEventListener('mouseleave', function(){
            clearTimeout(followTipTimer);
            followTip.style.display='none';
        });
    });
}
function showFollowTip(text){
    followTip.textContent = text;
    followTip.style.display='block';
    positionFollowTip();
}
function positionFollowTip(){
    var w=followTip.offsetWidth, h=followTip.offsetHeight;
    var vw=window.innerWidth || document.documentElement.clientWidth;
    var vh=window.innerHeight || document.documentElement.clientHeight;
    followTip.style.left = Math.max(8, Math.min(followMouseX+14, vw - w - 12)) + 'px';
    // 下方放不下就翻到光标上方
    followTip.style.top  = ((followMouseY+18+h > vh) ? Math.max(8, followMouseY-h-12) : followMouseY+18) + 'px';
}
function toggleFollowDetail(el){
    var tr = el.closest('tr');
    if(tr.nextElementSibling && tr.nextElementSibling.classList.contains('follow-detail-row')){
        tr.nextElementSibling.remove();
        el.style.fontWeight = '';
        return;
    }
    // 同时只展开一个客户，否则页面会被撑得很长不好翻
    Array.prototype.forEach.call(document.querySelectorAll('.follow-detail-row'), function(r){ r.remove(); });
    Array.prototype.forEach.call(document.querySelectorAll('.follow-brief'), function(b){ b.style.fontWeight=''; });

    var cid = el.getAttribute('data-cid');
    var row = document.createElement('tr');
    row.className = 'follow-detail-row';
    row.innerHTML = '<td colspan="10"><div style="padding:10px 14px;background:#f8fafc;border-top:1px solid #e2e8f0;">'
        + '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">'
        + '<strong style="font-size:13px;">全部跟进记录</strong>'
        + '<button class="btn btn-sm btn-primary" onclick="addFollowFor(' + cid + ')"><i class="fa-solid fa-plus"></i> 加跟进</button>'
        + '</div><div id="followBody' + cid + '" style="color:var(--gray-500);">加载中…</div></div></td>';
    tr.parentNode.insertBefore(row, tr.nextSibling);
    el.style.fontWeight = '600';
    loadFollowDetail(cid);
}
function addFollowFor(cid){
    var el = document.querySelector('.follow-brief[data-cid="'+cid+'"]');
    showFollowupModal(cid, el ? (el.getAttribute('data-cname')||'') : '');
}
function loadFollowDetail(cid){
    if(followCache[cid]){ renderFollowDetail(cid, followCache[cid]); return; }
    fetch('ajax.php?action=get_customer_followups&customer_id='+encodeURIComponent(cid))
    .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
    .then(function(resp){
        if(!resp.success){ renderFollowDetailMsg(cid, resp.message||'加载失败'); return; }
        followCache[cid] = resp.data || [];
        renderFollowDetail(cid, followCache[cid]);
    })
    .catch(function(err){ renderFollowDetailMsg(cid, '加载失败：'+err.message); });
}
function renderFollowDetailMsg(cid,msg){
    var box=document.getElementById('followBody'+cid);
    if(box) box.innerHTML='<span style="color:var(--danger);">'+escText(msg)+'</span>';
}
function renderFollowDetail(cid, rows){
    var box=document.getElementById('followBody'+cid);
    if(!box) return;
    if(!rows.length){ box.innerHTML='<span style="color:var(--gray-500);">暂无跟进记录</span>'; return; }
    // 展开区这张表也用固定布局：否则长跟进内容会把表格撑宽，连带把整页撑出横向滚动条
    var html='<table style="width:100%;table-layout:fixed;font-size:13px;">'
        + '<colgroup><col style="width:12%;"><col style="width:8%;"><col style="width:44%;">'
        + '<col style="width:8%;"><col style="width:8%;"><col style="width:13%;"><col style="width:7%;"></colgroup>'
        + '<thead><tr><th style="text-align:left;">时间</th><th style="text-align:left;">方式</th>'
        + '<th style="text-align:left;">内容</th><th style="text-align:left;">结果</th>'
        + '<th style="text-align:left;">跟进人</th><th style="text-align:left;">下次跟进</th>'
        + '<th style="text-align:left;">附件</th></tr></thead><tbody>';
    rows.forEach(function(r){
        html += '<tr>'
            + '<td style="white-space:nowrap;">'+escText(r.created_at)+'</td>'
            + '<td>'+escText(r.follow_type)+'</td>'
            + '<td style="white-space:pre-wrap;word-break:break-all;overflow-wrap:anywhere;">'+escText(r.content)+'</td>'
            + '<td>'+escText(r.result)+'</td>'
            + '<td>'+escText(r.user_name)+'</td>'
            + '<td>'+escText(r.next_follow_at||'—')+'</td>'
            + '<td>'+(r.attachment ? '<a href="../../'+escText(r.attachment)+'" target="_blank" rel="noopener">📎 '+escText(r.attachment_name||'查看')+'</a>' : '—')+'</td>'
            + '</tr>';
    });
    box.innerHTML = html + '</tbody></table>';
}
function toPool(id,name){
    if(!confirm('确定将客户【'+name+'】归入公海吗？\n归入公海后其他业务经理可以认领。')) return;
    var fd=new FormData();
    fd.append('action','to_pool');
    fd.append('customer_id',id);
    fd.append('_csrf_token',CSRF_TOKEN);
    safeFetch('ajax.php',{method:'POST',body:fd})
    .then(function(resp){
        if(resp.success){alert(resp.message);location.reload();}
        else alert(resp.message);
    }).catch(function(err){
        alert('操作失败：'+err.message);
    });
}
function toggleSelectAll(){
    var cb=document.getElementById('selectAll').checked;
    document.querySelectorAll('.rowCheck').forEach(function(c){c.checked=cb;syncCheck(c);});
}
function batchFlag(field,value){
    var ids=getCheckedIds();
    if(!ids.length){alert('请先勾选客户');return;}
    var names={pin:'置顶',mark:'标红'};
    if(!confirm('确定'+(value?'':'取消')+names[field]+'选中的'+ids.length+'个客户？')) return;
    postFlag(ids,field,value);
}
function toggleFlag(id,field,value){
    postFlag([id],field,value);
}
function postFlag(ids,field,value){
    var fd=new FormData();
    fd.append('action','set_flag');
    fd.append('ids',ids.join(','));
    fd.append('field',field);
    fd.append('value',String(value));
    fd.append('_csrf_token',CSRF_TOKEN);
    safeFetch('ajax.php',{method:'POST',body:fd})
    .then(function(resp){
        if(resp.success){
            // 关键：这里原来是 location.reload()。刷新会把「加载更多」点出来的几页全冲掉，
            // 只剩第一屏，等于白点；改成原地改 DOM
            applyFlagChange(ids,field,value);
            flashMsg(resp.message||'已更新');
        }
        else alert(resp.message);
    }).catch(function(err){
        alert('操作失败：'+err.message);
    });
}
// 原地更新置顶/标红的显示（已被折叠掉的行不在 DOM 里，服务端已改，下次加载自然生效）
function applyFlagChange(ids,field,value){
    var pinDelta=0, markDelta=0;
    ids.forEach(function(id){
        var tr=document.querySelector('#custTbody tr[data-id="'+id+'"]');
        if(!tr) return;
        if(field==='pin'){
            var was=tr.getAttribute('data-pinned')==='1';
            if(value&&!was){ tr.setAttribute('data-pinned','1'); pinDelta++; moveRowToTop(tr); }
            else if(!value&&was){ tr.removeAttribute('data-pinned'); pinDelta--; }
            // 取消置顶不挪位置：它该回到哪儿由排序规则说了算，硬挪反而让眼前的内容乱跳，
            // 下次加载/刷新自然归位
            setFlagBtn(tr,'btn-pin','pin',value,'var(--primary)','取消置顶','置顶到列表最前');
            var pi=tr.querySelector('.cust-pin-icon'); if(pi) pi.style.display=value?'inline':'none';
        }else{
            var wasM=tr.classList.contains('cust-marked');
            if(value&&!wasM){ tr.classList.add('cust-marked'); markDelta++; }
            else if(!value&&wasM){ tr.classList.remove('cust-marked'); markDelta--; }
            setFlagBtn(tr,'btn-mark','mark',value,'var(--danger)','取消标红','标红重点跟进');
            var mi=tr.querySelector('.cust-mark-icon'); if(mi) mi.style.display=value?'inline':'none';
        }
    });
    bumpCount('pinnedCount',pinDelta);
    bumpCount('markedCount',markDelta);
}
function setFlagBtn(tr,cls,field,value,onColor,onTitle,offTitle){
    var b=tr.querySelector('.'+cls);
    if(!b) return;
    b.setAttribute('onclick',"toggleFlag("+tr.getAttribute('data-id')+",'"+field+"',"+(value?0:1)+")");
    b.style.color=value?onColor:'var(--gray-500)';
    b.title=value?onTitle:offTitle;
}
function moveRowToTop(tr){
    var tbody=document.getElementById('custTbody');
    if(tbody&&tr.parentNode===tbody) tbody.insertBefore(tr,tbody.firstChild);
}
function bumpCount(id,delta){
    if(!delta) return;
    var el=document.getElementById(id);
    if(el) el.textContent=Math.max(0,parseInt(el.textContent,10)+delta);
}
function flashMsg(text){
    var d=document.getElementById('flashMsg');
    if(!d){
        d=document.createElement('div'); d.id='flashMsg';
        d.style.cssText='position:fixed;left:50%;bottom:40px;transform:translateX(-50%);z-index:9998;'
            +'background:rgba(17,24,39,.92);color:#fff;padding:8px 16px;border-radius:6px;font-size:13px;';
        document.body.appendChild(d);
    }
    d.textContent=text; d.style.display='block';
    clearTimeout(d._t); d._t=setTimeout(function(){d.style.display='none';},1800);
}
// 勾选状态存 JS 而不是 DOM：行被滑动窗口折叠掉之后勾选依然有效，
// 批量置顶/标红照样作用于这些已折叠的客户
function syncCheck(el){
    if(el.checked) checkedIds[el.value]=1; else delete checkedIds[el.value];
    updateCheckedInfo();
}
function getCheckedIds(){ return Object.keys(checkedIds); }
function updateCheckedInfo(){
    var el=document.getElementById('checkedInfo');
    if(!el) return;
    var ids=Object.keys(checkedIds);
    if(!ids.length){ el.textContent=''; return; }
    var inDom=document.querySelectorAll('.rowCheck:checked').length;
    var folded=ids.length-inDom;
    el.textContent='已勾选 '+ids.length+' 个'+(folded>0?('（含已折叠 '+folded+' 个）'):'');
}
function batchDelete(){
    var ids=getCheckedIds();
    if(!ids.length){alert('请选择要删除的客户');return;}
    if(!confirm('确定删除选中的'+ids.length+'个客户？')) return;
    var fd=new FormData();
    fd.append('action','batch_delete');
    fd.append('ids',ids.join(','));
    fd.append('_csrf_token',CSRF_TOKEN);
    safeFetch('ajax.php',{method:'POST',body:fd})
    .then(function(resp){
        if(resp.success){alert(resp.message);location.reload();}
        else alert(resp.message);
    }).catch(function(err){
        alert('操作失败：'+err.message);
    });
}
function showTransferModal(id,name){
    document.getElementById('transferCustId').value=id;
    document.getElementById('transferCustName').textContent=name;
    document.getElementById('transferToUser').selectedIndex=0;
    openModal('transferModal');
}
function doTransfer(e){
    e.preventDefault();
    var sel = document.getElementById('transferToUser');
    var toUser = parseInt(sel.value);
    if(!toUser){alert('请选择目标业务经理');return false;}
    if(toUser===CURRENT_USER_ID){alert('不能转移给自己');return false;}
    var toUserName = sel.options[sel.selectedIndex].text;
    if(!confirm('确定将客户【'+document.getElementById('transferCustName').textContent+'】转移给【'+toUserName+'】？')) return false;
    var fd=new FormData();
    fd.append('action','transfer');
    fd.append('customer_id',document.getElementById('transferCustId').value);
    fd.append('to_user_id',toUser);
    fd.append('_csrf_token',CSRF_TOKEN);
    var btn=document.querySelector('#transferModal button[type=submit]');
    if(btn){btn.disabled=true;btn.textContent='转移中...';}
    fetch('ajax.php',{method:'POST',body:fd})
    .then(function(r){
        if(!r.ok){
            return r.text().then(function(t){
                try{var resp=JSON.parse(t);alert(resp.message||'请求失败 HTTP '+r.status);}
                catch(e){alert('请求失败 HTTP '+r.status);}
            });
        }
        return r.text().then(function(t){
            try{
                var resp = JSON.parse(t);
                if(resp.success){
                    closeModal('transferModal');
                    location.reload();
                }else{
                    alert(resp.message||'转移失败');
                }
            }catch(e){
                // JSON解析失败但HTTP 200 — 可能转移已成功，刷新验证
                closeModal('transferModal');
                location.reload();
            }
        });
    }).catch(function(err){
        alert('转移失败：'+err.message);
    }).finally(function(){
        if(btn){btn.disabled=false;btn.textContent='确认转移';}
    });
    return false;
}

// ==================== 瀑布流：加载更多 + 滑动窗口 ====================
// 加载更多：点一次无刷新追加一页，滑到底再点继续追加
// 滑动窗口：DOM 里最多留 600 行，超了就把「上面已经看过的」行卸掉（置顶行永不卸），
// 客户涨到几千条时页面开销依然恒定，不会越点越卡
var PAGE_SIZE = <?= (int)$perPage ?>;      // 每次加载多少条 = 筛选栏里选的「每页条数」
var MAX_ROWS  = 600;                       // DOM 里最多保留多少行
var KEEP_ROWS = 300;                       // 一次折叠保留多少行（删一半留一半）
var MAX_RESTORE_PAGES = 20;                // 返回列表时最多自动补多少页，避免一次发几十个请求
var FILTER_QUERY = <?= json_encode($ajaxQuery, JSON_UNESCAPED_SLASHES) ?>;
var LIST_SIG = <?= json_encode($listSig) ?>;
var STORE_KEY = 'crm_cust_list_state';

var curPage   = 1;
var totalRows = <?= (int)$total ?>;
var loadedIds = {};      // 已加载过的客户 id：OFFSET 分页遇到「期间新增了客户」会返回重复行，靠它跳过
var checkedIds = {};     // 勾选状态（行被折叠后依然有效）
var foldedCount = 0;     // 上方已折叠掉多少行
var loadingMore = false;

function custTbody(){ return document.getElementById('custTbody'); }
function custRows(){ return Array.prototype.slice.call(custTbody().querySelectorAll('tr[data-id]')); }
function setPerPage(sel){
    document.cookie='crm_cust_per_page='+encodeURIComponent(sel.value)+';path=/;max-age=31536000';
    clearListState();     // 步长变了，上次的浏览位置没意义了，别再恢复
    sel.form.submit();
}
function backToStart(){
    // 回到开头：带上当前筛选条件，每页条数走 cookie，不用塞进 URL
    clearListState();     // 关键：不清掉的话页面一加载又按记录恢复到原来的位置，等于没回
    window.location.href='customers.php'+(FILTER_QUERY?('?'+FILTER_QUERY):'');
}
function clearListState(){
    try{ sessionStorage.removeItem(STORE_KEY); }catch(e){}
}
function appendRows(html){
    var tmp=document.createElement('tbody');
    tmp.innerHTML=html;
    var rows=tmp.querySelectorAll('tr[data-id]');
    var added=0;
    for(var i=0;i<rows.length;i++){
        var tr=rows[i], id=tr.getAttribute('data-id');
        if(loadedIds[id]) continue;                  // 去重
        loadedIds[id]=1;
        var cb=tr.querySelector('.rowCheck');
        if(cb&&checkedIds[id]) cb.checked=true;
        custTbody().appendChild(tr);
        added++;
    }
    if(added) bindFollowBriefs();   // 新行的「悬停看全文」也要绑上
    return added;
}
function fetchPage(page,cb){
    fetch('ajax.php?action=customer_rows&page='+page+'&per_page='+PAGE_SIZE+'&'+FILTER_QUERY)
    .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
    .then(function(resp){
        if(!resp.success){ cb(false, resp.message||'加载失败'); return; }
        appendRows(resp.data.html);
        totalRows = parseInt(resp.data.total,10) || totalRows;
        cb(true,'');
    })
    .catch(function(err){ cb(false, err.message); });
}
function loadMoreCustomers(){
    if(loadingMore) return;
    var btn=document.getElementById('loadMoreBtn');
    if(!btn||btn.style.display==='none') return;
    loadingMore=true; btn.disabled=true; btn.textContent='加载中...';
    var next=curPage+1;
    fetchPage(next,function(ok,msg){
        loadingMore=false;
        if(!ok){ alert('加载失败：'+msg); updateLoadMore(); return; }
        curPage=next;
        maybeFold();
        updateLoadMore();
    });
}
function maybeFold(){
    var rows=custRows();
    if(rows.length<=MAX_ROWS) return;
    var need=rows.length-KEEP_ROWS;                  // 删一半留一半，避免每点一次都折叠
    var scrollY=window.pageYOffset||document.documentElement.scrollTop;
    var removed=0, removedHeight=0;
    for(var i=0;i<rows.length&&removed<need;i++){
        var r=rows[i];
        if(r.getAttribute('data-pinned')==='1') continue;   // 置顶的客户永不折叠：置顶就是要一直在眼前
        if(r.getBoundingClientRect().bottom>=0) break;      // 只卸视口上方的行，卸正在看的内容会让页面跳
        removedHeight+=r.offsetHeight;
        r.parentNode.removeChild(r);
        removed++;
    }
    if(!removed) return;
    foldedCount+=removed;
    // 上面少了一段，文档变短，把滚动位置补回来 —— 用户眼前的内容保持不动
    window.scrollTo(0,Math.max(0,scrollY-removedHeight));
}
function updateLoadMore(){
    var btn=document.getElementById('loadMoreBtn'), info=document.getElementById('loadMoreInfo');
    if(!btn||!info) return;
    var n=custRows().length;
    var start=foldedCount+1, end=foldedCount+n, loaded=foldedCount+n;
    if(loaded>=totalRows){
        info.textContent='共 '+totalRows+' 条 · 已全部显示'+(foldedCount?('（上方折叠了 '+foldedCount+' 条）'):'');
        btn.style.display='none';
    }else{
        info.textContent='共 '+totalRows+' 条 · 当前显示第 '+start+' - '+end+' 条'+(foldedCount?('（上方已折叠 '+foldedCount+' 条）'):'');
        btn.style.display='';
        if(!loadingMore){
            btn.disabled=false;
            btn.innerHTML='<i class="fa-solid fa-chevron-down"></i> 共 <b>'+totalRows+'</b> 条 · 加载更多（还剩 '+(totalRows-loaded)+' 条）';
        }
    }
    var bar=document.getElementById('foldBar');
    if(bar){
        if(foldedCount>0){
            bar.style.display='flex';
            document.getElementById('foldText').textContent='已折叠上方 '+foldedCount+' 条 · 当前显示第 '+start+' - '+end+' 条（共 '+totalRows+' 条）';
        }else bar.style.display='none';
    }
}
// 离开页面时记下「加载到了第几页 + 滚动位置」：从客户详情返回时自动补回来，
// 否则瀑布流没有页码，返回就只剩第一屏
function saveListState(){
    try{
        sessionStorage.setItem(STORE_KEY, JSON.stringify({
            sig:LIST_SIG, page:curPage, folded:foldedCount,
            y:(window.pageYOffset||document.documentElement.scrollTop),
            checked:Object.keys(checkedIds)
        }));
    }catch(e){}
}
window.addEventListener('beforeunload', saveListState);
function restoreList(){
    var st=null;
    try{ st=JSON.parse(sessionStorage.getItem(STORE_KEY)||'null'); }catch(e){}
    if(!st||st.sig!==LIST_SIG||!(st.page>1)) return;      // 筛选条件变了就不恢复
    var target=Math.min(st.page, MAX_RESTORE_PAGES);
    if(target<=1) return;
    (st.checked||[]).forEach(function(id){ checkedIds[id]=1; });
    var info=document.getElementById('loadMoreInfo');
    if(info) info.textContent='正在恢复到上次的浏览位置…';
    (function step(){
        if(curPage>=target){ finishRestore(st); return; }
        fetchPage(curPage+1,function(ok){
            if(!ok){ finishRestore(st); return; }
            curPage++; step();
        });
    })();
}
function finishRestore(st){
    // 还原折叠：把上方多余的行卸掉（此时还没滚动，不用补偿滚动位置）
    var expect=(st.page*PAGE_SIZE)-(st.folded||0);
    var limit=Math.max(KEEP_ROWS, Math.min(MAX_ROWS, expect));
    var rows=custRows();
    for(var i=0;i<rows.length&&custRows().length>limit;i++){
        var r=rows[i];
        if(r.getAttribute('data-pinned')==='1'||!r.parentNode) continue;
        r.parentNode.removeChild(r);
        foldedCount++;
    }
    document.querySelectorAll('.rowCheck').forEach(function(c){ c.checked=!!checkedIds[c.value]; });
    updateCheckedInfo();
    updateLoadMore();
    if(st&&st.y) window.scrollTo(0, st.y);
}
document.addEventListener('DOMContentLoaded', function(){
    custRows().forEach(function(tr){ loadedIds[tr.getAttribute('data-id')]=1; });
    updateLoadMore();
    restoreList();
    window.addEventListener('scroll', function(){
        var b=document.getElementById('backTopBtn');
        if(b) b.style.display=(window.pageYOffset>600)?'flex':'none';
    });
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
