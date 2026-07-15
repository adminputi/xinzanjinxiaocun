<?php
/**
 * 登录验证码生成（SVG数学题，无需GD库）
 * 调用方式：captcha.php?refresh=1
 */
session_start();

// 生成随机数学题
$operators = [
    ['op' => '+', 'fn' => function($a,$b){return $a+$b;}],
    ['op' => '-', 'fn' => function($a,$b){return $a-$b;}],
    ['op' => '×', 'fn' => function($a,$b){return $a*$b;}],
];
$op = $operators[array_rand($operators)];
$a = rand(1, 20);
$b = rand(1, $op['op'] === '×' ? 9 : ($op['op'] === '-' ? $a : 20));

// 确保减法结果不为负
if ($op['op'] === '-' && $a < $b) {
    list($a, $b) = [$b, $a];
}

$answer = $op['fn']($a, $b);
$_SESSION['captcha_answer'] = $answer;
$_SESSION['captcha_time'] = time();

// 生成SVG图片
$width = 120;
$height = 44;
$text = "$a {$op['op']} $b = ?";

$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$height.'" viewBox="0 0 '.$width.' '.$height.'">';
$svg .= '<rect width="100%" height="100%" fill="#f8f9fa" rx="4"/>';
// 随机干扰线
for ($i = 0; $i < 3; $i++) {
    $x1 = rand(0, $width);
    $y1 = rand(0, $height);
    $x2 = rand(0, $width);
    $y2 = rand(0, $height);
    $color = '#' . str_pad(dechex(rand(150, 210)), 2, '0', STR_PAD_LEFT) . str_pad(dechex(rand(150, 210)), 2, '0', STR_PAD_LEFT) . str_pad(dechex(rand(150, 210)), 2, '0', STR_PAD_LEFT);
    $svg .= '<line x1="'.$x1.'" y1="'.$y1.'" x2="'.$x2.'" y2="'.$y2.'" stroke="'.$color.'" stroke-width="1"/>';
}
// 随机噪点
for ($i = 0; $i < 20; $i++) {
    $cx = rand(0, $width);
    $cy = rand(0, $height);
    $color = '#' . str_pad(dechex(rand(100, 200)), 2, '0', STR_PAD_LEFT) . str_pad(dechex(rand(100, 200)), 2, '0', STR_PAD_LEFT) . str_pad(dechex(rand(100, 200)), 2, '0', STR_PAD_LEFT);
    $svg .= '<circle cx="'.$cx.'" cy="'.$cy.'" r="1" fill="'.$color.'"/>';
}
// 主文字
$svg .= '<text x="'.($width/2).'" y="'.($height/2+6).'" text-anchor="middle" font-family="Arial,Consolas,monospace" font-size="20" font-weight="bold" fill="#334155">' . htmlspecialchars($text) . '</text>';
$svg .= '</svg>';

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
echo $svg;
