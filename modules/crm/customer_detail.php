<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('crm_customer_view');
$pdo = getDB();
require_once __DIR__ . '/../../includes/migration.php';
run_migrations();
$isAdmin = (get_user_role() === 'admin');
$userId = get_user_id();
// 是否有编辑跟进记录的权限（管理员恒为 true，其他角色需在角色管理中勾选 crm_followup_edit）
$canEditFollowup = check_permission('crm_followup_edit');
$id = intval($_GET['id'] ?? 0);
if (!$id) die('缺少客户ID');

$stmt = $pdo->prepare("SELECT c.*, s.name as source_name, u.real_name as owner_name,
    (SELECT COALESCE(SUM(total_amount),0) FROM sales_orders WHERE customer_id=c.id AND status NOT IN('draft','cancelled')) as total_sales,
    (SELECT COUNT(*) FROM sales_orders WHERE customer_id=c.id) as order_count,
    (SELECT COUNT(*) FROM customer_followups WHERE customer_id=c.id) as followup_count,
    " . sql_customer_deal_stats('c') . ",
    (SELECT COALESCE(SUM(total_amount - received_amount),0) FROM sales_orders WHERE customer_id=c.id AND status NOT IN('draft','cancelled')) as ar_balance
    FROM customers c
    LEFT JOIN customer_sources s ON c.source_id=s.id
    LEFT JOIN users u ON c.owner_id=u.id
    WHERE c.id=?");
$stmt->execute([$id]);
$cust = $stmt->fetch();
if (!$cust) die('客户不存在');

// 应收余额改用统一口径：订单总额 - 已收 + 期初应收 - 已确认退货
// 与首页看板、应收应付、账龄分析、客户对账保持一致
$arRows = get_ar_by_customer($id);
$cust['ar_balance'] = $arRows ? $arRows[0]['balance'] : 0;

// 跟进记录
$followups = $pdo->prepare("SELECT f.*, u.real_name as user_name, ue.real_name as updated_by_name,
    (SELECT MIN(f2.created_at) FROM customer_followups f2
      WHERE f2.customer_id = f.customer_id AND f2.created_at > f.next_follow_at) AS done_after_at
    FROM customer_followups f LEFT JOIN users u ON f.user_id=u.id LEFT JOIN users ue ON f.updated_by=ue.id WHERE f.customer_id=? ORDER BY f.created_at DESC");
$followups->execute([$id]);
$followupList = $followups->fetchAll();

// 销售订单
$orders = $pdo->prepare("SELECT * FROM sales_orders WHERE customer_id=? ORDER BY id DESC");
$orders->execute([$id]);
$orderList = $orders->fetchAll();

// 转移记录
$transfers = $pdo->prepare("SELECT t.*, fu.real_name as from_name, tu.real_name as to_name, o.real_name as operator_name FROM customer_transfer_logs t LEFT JOIN users fu ON t.from_user_id=fu.id LEFT JOIN users tu ON t.to_user_id=tu.id LEFT JOIN users o ON t.operator_id=o.id WHERE t.customer_id=? ORDER BY t.created_at DESC");
$transfers->execute([$id]);
$transferList = $transfers->fetchAll();

// 所有用户（管理员分配用 + 转移客户用）
$users = $pdo->query("SELECT id,real_name FROM users WHERE status=1 ORDER BY real_name")->fetchAll();
$sources = $pdo->query("SELECT * FROM customer_sources WHERE status=1 ORDER BY sort_order")->fetchAll();
$statusLabels = ['draft'=>'草稿','confirmed'=>'已锁定','shipped'=>'已出库','partial'=>'部分','completed'=>'已完成','cancelled'=>'已取消'];
$statusBadges = ['draft'=>'warning','confirmed'=>'info','shipped'=>'success','partial'=>'warning','completed'=>'success','cancelled'=>'danger'];
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-user"></i> 客户详情</h1>
    <div class="page-actions">
        <a href="customers.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> 返回列表</a>
    </div>
</div>

<!-- 信息卡片 -->
<div class="card">
    <div class="card-body">
        <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;">
            <div>
                <h2 style="margin:0 0 8px;"><?=htmlspecialchars($cust['name'])?> <small style="color:var(--gray-500);">[<?=htmlspecialchars($cust['code'])?>]</small><?php if (customer_is_deal($cust)): ?> <span class="badge badge-success" title="<?=htmlspecialchars(customer_deal_tip($cust))?>">已成交</span><?php endif; ?></h2>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:14px;">
                    <div><strong>类型：</strong><?=$cust['type']=='company'?'企业':'个人'?></div>
                    <div><strong>电话：</strong><?=htmlspecialchars($cust['phone'])?:'-'?></div>
                    <div><strong>联系人：</strong><?=htmlspecialchars($cust['contact'])?:'-'?></div>
                    <div><strong>公司：</strong><?=htmlspecialchars($cust['company'])?:'-'?></div>
                    <div><strong>微信：</strong><?=htmlspecialchars($cust['wechat'])?:'-'?></div>
                    <div><strong>邮箱：</strong><?=htmlspecialchars($cust['email'])?:'-'?></div>
                    <div><strong>地址：</strong><?=htmlspecialchars($cust['address'])?:'-'?></div>
                    <div><strong>来源：</strong><?=htmlspecialchars($cust['source_name'])?:'-'?></div>
                    <div><strong>意向：</strong><?php if($cust['intention']): ?><span class="badge badge-<?=$cust['intention']=='高'?'success':($cust['intention']=='中'?'warning':'info')?>"><?=$cust['intention']?></span><?php else: ?>-<?php endif; ?></div>
                    <div><strong>意向产品：</strong><?=htmlspecialchars($cust['intended_product'])?:'-'?></div>
                    <div><strong>开发日期：</strong><?=($cust['developed_at'] ?? '') ?: '--'?></div>
                    <div><strong>归属：</strong><?=htmlspecialchars($cust['owner_name']?:'公海')?></div>
                    <?php if ($cust['remark']): ?><div style="grid-column:1/-1;"><strong>备注：</strong><?=nl2br(htmlspecialchars($cust['remark']))?></div><?php endif; ?>
                </div>
            </div>
            <div style="background:var(--gray-50);border-radius:8px;padding:16px;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div style="text-align:center;"><div style="font-size:24px;font-weight:bold;color:var(--primary);">¥<?=format_money($cust['total_sales'])?></div><div style="font-size:12px;color:var(--gray-500);">累计消费</div></div>
                    <div style="text-align:center;"><div style="font-size:24px;font-weight:bold;"><?=$cust['order_count']?></div><div style="font-size:12px;color:var(--gray-500);">订单数</div></div>
                    <div style="text-align:center;"><div style="font-size:24px;font-weight:bold;color:var(--danger);">¥<?=format_money($cust['ar_balance'])?></div><div style="font-size:12px;color:var(--gray-500);">应收余额</div></div>
                    <div style="text-align:center;"><div style="font-size:24px;font-weight:bold;"><?=$cust['followup_count']?></div><div style="font-size:12px;color:var(--gray-500);">跟进次数</div></div>
                </div>
                <?php if ($cust['last_followed_at']): ?><div style="text-align:center;margin-top:8px;font-size:12px;color:var(--gray-500);">最后跟进：<?=format_datetime_short($cust['last_followed_at'])?></div><?php endif; ?>
                <?php if (customer_is_deal($cust)): ?><div style="text-align:center;margin-top:4px;font-size:12px;color:var(--success,#28a745);">
                    成交 <?=intval($cust['deal_order_count'])?> 单 · 累计 ¥<?=format_money($cust['deal_amount'])?>
                    · 最近成交 <?=($cust['last_deal_date'] && $cust['last_deal_date'] !== '0000-00-00') ? htmlspecialchars($cust['last_deal_date']) : '--'?>
                </div><?php endif; ?>
            </div>
        </div>

        <!-- 操作按钮 -->
        <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--gray-200);display:flex;gap:8px;flex-wrap:wrap;">
            <button class="btn btn-primary" onclick="showFollowupModal(<?=$cust['id']?>,'<?=htmlspecialchars(addslashes($cust['name']))?>')"><i class="fa-solid fa-comment-dots"></i> 添加跟进</button>
            <a href="../sales/order_form.php?customer_id=<?=$cust['id']?>" class="btn btn-primary"><i class="fa-solid fa-file-invoice-dollar"></i> 新建销售订单</a>
            <?php if ($cust['owner_id'] == $userId && !$cust['in_pool']): ?>
            <button class="btn btn-outline" style="color:var(--warning);" onclick="toPool(<?=$cust['id']?>,'<?=htmlspecialchars(addslashes($cust['name']))?>')"><i class="fa-solid fa-water"></i> 归入公海</button>
            <?php endif; ?>
            <?php if ($cust['owner_id'] == $userId && !$cust['in_pool']): ?>
            <button class="btn btn-outline" onclick="showTransferModal(<?=$cust['id']?>,'<?=htmlspecialchars(addslashes($cust['name']))?>')"><i class="fa-solid fa-arrow-right-arrow-left"></i> 转移客户</button>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
            <button class="btn btn-outline" onclick="showLinkOrderModal()"><i class="fa-solid fa-link"></i> 关联已有订单</button>
            <button class="btn btn-outline" style="color:var(--warning);" onclick="showAssignModal(<?=$cust['id']?>)"><i class="fa-solid fa-user-plus"></i> 强制分配</button>
            <button class="btn btn-outline" onclick="showTransferModal(<?=$cust['id']?>,'<?=htmlspecialchars(addslashes($cust['name']))?>')"><i class="fa-solid fa-arrow-right-arrow-left"></i> 转移客户</button>
            <?php if (!$cust['in_pool']): ?><button class="btn btn-outline" style="color:var(--danger);" onclick="forceToPool(<?=$cust['id']?>)"><i class="fa-solid fa-water"></i> 强制归入公海</button><?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Tab 导航 -->
<style>
.tab-nav{display:flex;gap:0;border-bottom:2px solid var(--gray-200);margin:16px 0;}
.tab-nav a{padding:10px 20px;text-decoration:none;color:var(--gray-600);border-bottom:2px solid transparent;margin-bottom:-2px;font-size:14px;transition:all .2s;}
.tab-nav a:hover{color:var(--primary);}
.tab-nav a.active{color:var(--primary);border-bottom-color:var(--primary);font-weight:600;}
.tab-panel{display:none;}
.tab-panel.active{display:block;}
.timeline{padding-left:24px;border-left:2px solid var(--gray-200);}
.timeline-item{position:relative;padding-bottom:20px;padding-left:12px;}
.timeline-item:before{content:'';position:absolute;left:-29px;top:4px;width:12px;height:12px;border-radius:50%;background:var(--gray-300);border:2px solid #fff;box-shadow:0 0 0 2px var(--gray-300);}
.timeline-item:last-child{border-left:none;}
</style>

<div class="tab-nav">
    <a href="#tab-followups" class="active" onclick="switchTab('followups',event)">跟进记录</a>
    <a href="#tab-orders" onclick="switchTab('orders',event)">销售订单</a>
    <a href="#tab-transfers" onclick="switchTab('transfers',event)">转移记录</a>
</div>

<!-- 跟进记录 -->
<div class="card tab-panel active" id="tab-followups">
    <div class="card-body">
    <?php if ($followupList): ?>
        <div class="timeline">
        <?php foreach($followupList as $f): ?>
        <div class="timeline-item">
            <div style="font-size:12px;color:var(--gray-500);"><?=$f['created_at']?> · <?=htmlspecialchars($f['follow_type'])?> · <?=htmlspecialchars($f['user_name'])?></div>
            <div style="font-size:14px;margin-top:4px;"><?=nl2br(htmlspecialchars($f['content']))?></div>
            <div style="margin-top:4px;display:flex;gap:8px;align-items:center;">
                <span class="badge badge-<?=$f['result']=='已成交'?'success':($f['result']=='有意向'?'primary':($f['result']=='无意向'?'danger':'warning'))?>"><?=$f['result']?></span>
                <?php if ($f['next_follow_at']): ?>
                <?php $fSt = follow_plan_status($f['next_follow_at'], $f['result'], $f['done_after_at'] ?? null); ?>
                <span style="font-size:12px;color:<?=$fSt['state']==='overdue'?'var(--danger);font-weight:600':'var(--gray-500)'?>;">计划下次跟进：<?=format_datetime_short($f['next_follow_at'])?><?php if ($fSt['state'] === 'overdue'): ?>（已逾期<?=$fSt['days']>0?$fSt['days'].'天':''?>）<?php elseif ($fSt['state'] === 'late_done'): ?>（<?=$fSt['days']>0?'逾期'.$fSt['days'].'天后跟进':'当天已跟进'?>）<?php endif; ?></span>
                <?php endif; ?>
                <?php if ($canEditFollowup): ?>
                <button class="btn btn-sm btn-outline" style="padding:2px 8px;font-size:12px;" onclick="editFollowup(<?=$f['id']?>)" title="编辑此跟进"><i class="fa-solid fa-pen"></i> 编辑</button>
                <?php endif; ?>
            </div>
            <?php if ($f['attachment']): ?>
            <div style="margin-top:4px;">📎 <a href="../../<?=htmlspecialchars($f['attachment'])?>" target="_blank" download="<?=htmlspecialchars($f['attachment_name'] ?? '')?>" title="<?=htmlspecialchars($f['attachment_name'] ?? '')?>"><?=htmlspecialchars($f['attachment_name'] ?? '') ?: '查看附件'?></a></div>
            <?php endif; ?>
            <?php if (!empty($f['updated_by'])): ?>
            <div style="font-size:12px;color:var(--gray-400);margin-top:2px;"><i class="fa-solid fa-pen"></i> 由 <?=htmlspecialchars($f['updated_by_name'] ?: ('用户#'.$f['updated_by']))?> 修改于 <?=$f['updated_at']?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state"><i class="fa-solid fa-comments"></i><p>暂无跟进记录</p></div>
    <?php endif; ?>
    </div>
</div>

<!-- 销售订单 -->
<div class="card tab-panel" id="tab-orders">
    <div class="card-body" style="padding:0;">
    <div class="table-container"><table>
    <thead><tr><th>单号</th><th>日期</th><th>金额</th><th>已收</th><th>状态</th><th>操作</th></tr></thead>
    <tbody>
    <?php if ($orderList): foreach($orderList as $o): ?>
    <tr>
        <td><a href="../sales/order_view.php?id=<?=$o['id']?>"><strong><?=htmlspecialchars($o['bill_no'])?></strong></a></td>
        <td><?=$o['order_date']?></td>
        <td>¥<?=format_money($o['total_amount'])?></td>
        <td>¥<?=format_money($o['received_amount'])?></td>
        <td><span class="badge badge-<?=$statusBadges[$o['status']]??'gray'?>"><?=$statusLabels[$o['status']]??$o['status']?></span></td>
        <td><a href="../sales/order_view.php?id=<?=$o['id']?>" class="btn btn-sm btn-outline">查看</a></td>
    </tr>
    <?php endforeach; else: ?>
    <tr><td colspan="6"><div class="empty-state"><i class="fa-solid fa-file-invoice-dollar"></i><p>暂无销售订单</p></div></td></tr>
    <?php endif; ?>
    </tbody></table></div></div>
</div>

<!-- 转移记录 -->
<div class="card tab-panel" id="tab-transfers">
    <div class="card-body">
    <?php if ($transferList): ?>
        <div class="timeline">
        <?php foreach($transferList as $t): ?>
        <div class="timeline-item">
            <div style="font-size:12px;color:var(--gray-500);"><?=$t['created_at']?></div>
            <div style="font-size:14px;">
                <?php if($t['action']=='to_pool'): ?>
                    <span style="color:var(--warning);">归入公海</span> — <?=htmlspecialchars($t['from_name']?:'-')?> 放弃
                <?php elseif($t['action']=='claim'): ?>
                    <span style="color:var(--success);">认领</span> — 公海 → <?=htmlspecialchars($t['to_name']?:'-')?>
                <?php else: ?>
                    <span style="color:var(--primary);">分配</span> — <?=htmlspecialchars($t['from_name']?:'公海')?> → <?=htmlspecialchars($t['to_name']?:'-')?>
                <?php endif; ?>
                <?php if ($t['operator_name']): ?><span style="font-size:12px;color:var(--gray-500);">操作人：<?=htmlspecialchars($t['operator_name'])?></span><?php endif; ?>
            </div>
            <?php if ($t['remark']): ?><div style="font-size:12px;color:var(--gray-600);"><?=htmlspecialchars($t['remark'])?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state"><i class="fa-solid fa-arrows-rotate"></i><p>暂无转移记录</p></div>
    <?php endif; ?>
    </div>
</div>

<!-- 跟进弹窗 -->
<div class="modal-overlay" id="followupModal"><div class="modal modal-md"><div class="modal-header"><h3 class="modal-title"><span id="fuModalTitle">添加跟进</span> - <span id="fuCustomerName"></span></h3><button class="modal-close" onclick="closeModal('followupModal')">&times;</button></div>
<form id="followupForm" onsubmit="return saveFollowup(event)" enctype="multipart/form-data">
<?=csrf_field()?>
<input type="hidden" name="customer_id" id="fuCustomerId">
<input type="hidden" name="id" id="fuId">
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
        <textarea name="content" class="form-control" rows="4" required></textarea>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">上传附件</label>
            <input type="file" name="attachment" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.pdf,.zip,.rar">
            <small style="color:var(--gray-500);">支持 JPG/PNG/GIF/WEBP 图片、Word/Excel/PPT、PDF、RAR/ZIP，单个最大 50MB</small>
            <div id="fuCurrentAttach" style="font-size:12px;color:var(--gray-500);margin-top:4px;"></div>
            <label id="fuRemoveWrap" style="display:none;font-size:12px;margin-top:4px;cursor:pointer;color:var(--danger);"><input type="checkbox" id="fuRemove"> 删除当前附件</label>
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
    <button type="submit" class="btn btn-primary" id="fuSubmitBtn">保存跟进</button>
</div>
</form></div></div>

<!-- 关联订单弹窗 -->
<div class="modal-overlay" id="linkOrderModal"><div class="modal modal-md"><div class="modal-header"><h3 class="modal-title">关联已有订单</h3><button class="modal-close" onclick="closeModal('linkOrderModal')">&times;</button></div>
<div class="modal-body">
    <div class="mb-2"><input type="text" id="orderSearch" class="form-control" placeholder="搜索订单号..." onkeydown="if(event.key==='Enter')searchOrders()"></div>
    <div id="orderSearchResult" style="max-height:300px;overflow-y:auto;"></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('linkOrderModal')">取消</button></div>
</div></div>

<!-- 分配弹窗 -->
<div class="modal-overlay" id="assignModal"><div class="modal modal-sm"><div class="modal-header"><h3 class="modal-title">分配客户</h3><button class="modal-close" onclick="closeModal('assignModal')">&times;</button></div>
<form onsubmit="return doAssign(event)">
<?=csrf_field()?>
<input type="hidden" id="assignCustomerId">
<div class="modal-body">
    <div class="form-group"><label class="form-label">分配给 <span class="required">*</span></label>
        <select id="assignToUser" class="form-control" required>
            <option value="">请选择业务经理</option>
            <?php if ($isAdmin): foreach($users as $u): ?>
            <option value="<?=$u['id']?>"><?=htmlspecialchars($u['real_name'])?></option>
            <?php endforeach; endif; ?>
        </select>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-outline" onclick="closeModal('assignModal')">取消</button>
    <button type="submit" class="btn btn-primary">确认分配</button>
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
            <?php foreach($users as $u): if($u['id']==$userId) continue; ?>
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
function switchTab(name,e){
    if(e)e.preventDefault();
    document.querySelectorAll('.tab-nav a').forEach(function(a){a.classList.remove('active');});
    document.querySelectorAll('.tab-panel').forEach(function(p){p.classList.remove('active');});
    document.querySelector('.tab-nav a[href="#tab-'+name+'"]').classList.add('active');
    document.getElementById('tab-'+name).classList.add('active');
}
var CAN_EDIT_FOLLOWUP = <?= $canEditFollowup ? 'true' : 'false' ?>;
function escHtml(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
// 清空表单并切换“新增 / 编辑”两种模式（form.reset() 会清空 hidden 值，之后需回填）
function resetFollowupForm(mode){
    var form=document.getElementById('followupForm');
    form.reset();
    document.getElementById('fuRemove').checked=false;
    document.getElementById('fuRemoveWrap').style.display='none';
    document.getElementById('fuCurrentAttach').innerHTML='';
    document.getElementById('fuModalTitle').textContent = mode==='edit' ? '编辑跟进' : '添加跟进';
    document.getElementById('fuSubmitBtn').textContent = mode==='edit' ? '保存修改' : '保存跟进';
    return form;
}
function showFollowupModal(cid,cname){
    resetFollowupForm('add');
    document.getElementById('fuId').value='';
    document.getElementById('fuCustomerId').value=cid;
    document.getElementById('fuCustomerName').textContent=cname;
    openModal('followupModal');
}
// 编辑已有跟进记录（需 crm_followup_edit 权限）
function editFollowup(id){
    if(!CAN_EDIT_FOLLOWUP){ alert('无权限：仅管理员或被授权角色可修改跟进记录'); return; }
    fetch('ajax.php?action=get_followup_detail&id='+id)
    .then(function(r){
        if(!r.ok) throw new Error('HTTP '+r.status);
        var ct = r.headers.get('content-type')||'';
        if(ct.indexOf('application/json')===-1) throw new Error('非JSON响应');
        return r.json();
    })
    .then(function(resp){
        if(!resp.success || !resp.data){ alert(resp.message||'加载失败'); return; }
        var f=resp.data;
        var form=resetFollowupForm('edit');
        document.getElementById('fuId').value=f.id;
        document.getElementById('fuCustomerId').value=f.customer_id;
        document.getElementById('fuCustomerName').textContent=f.customer_name||'';
        form.querySelector('[name=follow_type]').value=f.follow_type||'电话';
        form.querySelector('[name=result]').value=f.result||'待跟进';
        form.querySelector('[name=content]').value=f.content||'';
        form.querySelector('[name=next_follow_at]').value=toDatetimeLocal(f.next_follow_at);
        var cur=document.getElementById('fuCurrentAttach');
        if(f.attachment){
            cur.innerHTML='当前附件：<a href="../../'+escHtml(f.attachment)+'" target="_blank" download="'+escHtml(f.attachment_name||'')+'">📎 '+escHtml(f.attachment_name||'查看附件')+'</a>';
            document.getElementById('fuRemoveWrap').style.display='block';
        }else{
            cur.innerHTML='当前无附件';
        }
        openModal('followupModal');
    })
    .catch(function(err){ alert('加载失败：'+err.message); });
}
function saveFollowup(e){
    e.preventDefault();
    var form=document.getElementById('followupForm');
    var editId=document.getElementById('fuId').value;
    var fileInput=form.querySelector('input[type=file][name=attachment]');
    if(fileInput && fileInput.files && fileInput.files[0] && fileInput.files[0].size > 50*1024*1024){
        alert('附件大小超过 50MB 上限，请压缩后再上传');
        return false;
    }
    var fd=new FormData(form);
    fd.append('action', editId ? 'update_followup' : 'add_followup');
    if(editId && document.getElementById('fuRemove').checked) fd.append('remove_attachment','1');
    var btn = document.getElementById('fuSubmitBtn');
    if(btn){btn.disabled=true;btn.textContent='保存中...';}
    fetch('ajax.php',{method:'POST',body:fd})
    .then(function(r){
        if(!r.ok) throw new Error('HTTP '+r.status);
        var ct = r.headers.get('content-type')||'';
        if(ct.indexOf('application/json')===-1) throw new Error('非JSON响应');
        return r.json();
    })
    .then(function(resp){
        if(resp.success){
            closeModal('followupModal');
            alert(resp.message);
            location.reload();
        } else {
            alert(resp.message);
        }
    })
    .catch(function(err){
        alert('跟进保存失败：'+err.message);
    })
    .finally(function(){
        if(btn){btn.disabled=false;btn.textContent = editId ? '保存修改' : '保存跟进';}
    });
    return false;
}
function toPool(id,name){
    if(!confirm('确定将客户【'+name+'】归入公海？'))return;
    var fd=new FormData();
    fd.append('action','to_pool');
    fd.append('customer_id',id);
    fd.append('_csrf_token',CSRF_TOKEN);
    fetch('ajax.php',{method:'POST',body:fd})
    .then(function(r){
        if(!r.ok) throw new Error('HTTP '+r.status);
        return r.json();
    })
    .then(function(resp){
        if(resp.success){alert(resp.message);location.reload();}
        else alert(resp.message);
    })
    .catch(function(err){alert('操作失败：'+err.message);});
}
function forceToPool(id){
    if(!confirm('确定强制将该客户归入公海？'))return;
    var fd=new FormData();
    fd.append('action','to_pool');
    fd.append('customer_id',id);
    fd.append('_csrf_token',CSRF_TOKEN);
    fetch('ajax.php',{method:'POST',body:fd})
    .then(function(r){
        if(!r.ok) throw new Error('HTTP '+r.status);
        return r.json();
    })
    .then(function(resp){
        if(resp.success){alert(resp.message);location.reload();}
        else alert(resp.message);
    })
    .catch(function(err){alert('操作失败：'+err.message);});
}
function showLinkOrderModal(){
    document.getElementById('orderSearch').value='';
    document.getElementById('orderSearchResult').innerHTML='';
    openModal('linkOrderModal');
}
function searchOrders(){
    var kw=document.getElementById('orderSearch').value.trim();
    document.getElementById('orderSearchResult').innerHTML='<p style="padding:8px;color:var(--gray-500);">搜索中...</p>';
    fetch('ajax.php?action=search_orders&search='+encodeURIComponent(kw))
    .then(function(r){
        if(!r.ok) throw new Error('请求失败');
        return r.json();
    })
    .then(function(resp){
        if(!resp.success||!resp.data.length){
            document.getElementById('orderSearchResult').innerHTML='<p style="padding:12px;color:var(--gray-500);text-align:center;">未找到订单</p>';
            return;
        }
        var html='<table style="width:100%;font-size:13px;"><thead><tr><th>单号</th><th>日期</th><th>金额</th><th></th></tr></thead><tbody>';
        resp.data.forEach(function(o){
            html+='<tr>';
            html+='<td><strong>'+escHtml(o.bill_no)+'</strong></td>';
            html+='<td>'+escHtml(o.order_date)+'</td>';
            html+='<td>¥'+parseFloat(o.total_amount||0).toFixed(2)+'</td>';
            html+='<td><button class="btn btn-sm btn-primary" onclick="linkOrder('+o.id+',\''+escHtml(o.bill_no)+'\')">关联</button></td>';
            html+='</tr>';
        });
        html+='</tbody></table>';
        document.getElementById('orderSearchResult').innerHTML=html;
    })
    .catch(function(err){
        document.getElementById('orderSearchResult').innerHTML='<p style="padding:8px;color:var(--danger);">搜索失败</p>';
    });
}
function linkOrder(oid,billNo){
    if(!confirm('确定将订单【'+billNo+'】关联到当前客户？'))return;
    var fd=new FormData();
    fd.append('action','link_order');
    fd.append('customer_id','<?=$id?>');
    fd.append('order_id',oid);
    fd.append('_csrf_token',CSRF_TOKEN);
    fetch('ajax.php',{method:'POST',body:fd})
    .then(function(r){
        if(!r.ok) throw new Error('请求失败');
        return r.json();
    })
    .then(function(resp){
        if(resp.success){closeModal('linkOrderModal');location.reload();}
        else alert(resp.message);
    })
    .catch(function(err){alert('操作失败：'+err.message);});
}
function showAssignModal(cid){
    document.getElementById('assignCustomerId').value=cid;
    document.getElementById('assignToUser').selectedIndex=0;
    openModal('assignModal');
}
function doAssign(e){
    e.preventDefault();
    var fd=new FormData();
    fd.append('action','assign');
    fd.append('customer_id',document.getElementById('assignCustomerId').value);
    fd.append('to_user_id',document.getElementById('assignToUser').value);
    fd.append('_csrf_token',CSRF_TOKEN);
    fetch('ajax.php',{method:'POST',body:fd})
    .then(function(r){
        if(!r.ok) throw new Error('请求失败');
        return r.json();
    })
    .then(function(resp){
        if(resp.success){closeModal('assignModal');alert(resp.message);location.reload();}
        else alert(resp.message);
    })
    .catch(function(err){alert('操作失败：'+err.message);});
    return false;
}
function escHtml(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
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
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
