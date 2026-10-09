/**
 * 金额 → 中文大写（全站唯一实现）
 *
 * 来源：原先 quote_view / order_view / outstock_view / print_tpl / contract_view / contract_form
 * 各自内联了一份 numToCny，且是两套不同写法，遇到「万位是 0」的金额会算错（例如
 * 107840 算出「壹拾零柒仟捌佰肆拾元整」少了「万」，200000 甚至只算出「贰拾元整」）。
 * 现在统一由本文件提供，单据上任何地方的大写金额都出自同一份代码。
 *
 * 由 includes/header.php 在页面 <head> 里引入，因此页面里的内联脚本（含顶层直接调用的语句）
 * 执行前它一定已经就绪 —— 请勿再在任何页面内联定义同名函数。
 *
 * 规则按《支付结算办法》正确填写中文大写金额：
 *   1. 四位一节，节内单位 拾/佰/仟，节单位 （空）/万/亿/万亿
 *   2. 只要某一节内出现过非零数字，就必须带上该节的节单位（即使该节最低位是 0）—— 此前的 bug 就出在这
 *   3. 数字中间有 0 读一个「零」，连续多个 0 只读一个；末尾的 0 不读
 *   4. 元后无角分时写「整」；角为 0 分不为 0 时，元后要补「零」
 *
 * @param {number|string} num
 * @returns {string} 例如 numToCny(107840) === '壹拾万零柒仟捌佰肆拾元整'
 */
function numToCny(num) {
    if (num === null || num === undefined || num === '' || isNaN(num)) return '零元整';
    var n = Number(num);
    if (n === 0) return '零元整';
    // 负数：按同样规则换算后加「负」前缀
    var neg = n < 0;
    n = Math.abs(n);
    // 1e15 = 999 万亿以内都够用，且远低于 JS 整数的安全范围（2^53≈9.007e15），不会有精度误差
    if (n >= 1e15) return '金额超出范围';

    var digit = ['零','壹','贰','叁','肆','伍','陆','柒','捌','玖'];
    var unit = ['','拾','佰','仟'];
    var bigUnit = ['','万','亿','万亿'];
    var integerPart = Math.floor(n);
    var decimalPart = Math.round((n - integerPart) * 100);   // 分（四舍五入到两位小数）

    var result = '';
    var zeroFlag = false;
    if (integerPart === 0) {
        result = '零';
    } else {
        var strInt = String(integerPart);
        var len = strInt.length;
        for (var i = 0; i < len; i++) {
            var d = parseInt(strInt.charAt(i), 10);
            var pos = len - i - 1;              // 该位对应的幂次（个位为 0）
            var unitPos = pos % 4;              // 节内位置：3=千 2=百 1=十 0=个
            var bigPos = Math.floor(pos / 4);   // 节号：0=个节 1=万节 2=亿节 3=万亿节
            if (d === 0) {
                zeroFlag = true;
            } else {
                if (zeroFlag && result !== '') result += '零';   // 前一个有效数字之后出现过 0
                zeroFlag = false;
                result += digit[d];
                if (unitPos > 0) result += unit[unitPos];
            }
            // 刚读完一节的最低位：只要本节出现过非零数字就要补节单位（这里是原实现出错的地方，
            // 以前只检查了当前这一位，导致 10,7840 / 20,0000 这类数字的「万」被吞掉）
            if (unitPos === 0 && bigPos > 0) {
                var hasNonZero = false;
                for (var j = Math.max(0, i - 3); j <= i; j++) {
                    if (parseInt(strInt.charAt(j), 10) !== 0) { hasNonZero = true; break; }
                }
                if (hasNonZero) result += bigUnit[bigPos];
            }
        }
    }
    if (neg) result = '负' + result;
    result += '元';

    if (decimalPart === 0) {
        result += '整';
    } else {
        var jiao = Math.floor(decimalPart / 10);
        var fen = decimalPart % 10;
        if (jiao > 0) result += digit[jiao] + '角';
        else if (fen > 0) result += '零';      // 角为 0、分不为 0：元后要写「零」
        if (fen > 0) result += digit[fen] + '分';
    }
    return result;
}
