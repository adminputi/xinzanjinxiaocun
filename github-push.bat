@echo off
setlocal enabledelayedexpansion
chcp 65001 >nul
cd /d "%~dp0"

echo.
echo ============================================
echo   鑫瓒进销存 GitHub 一键提交推送
echo ============================================
echo.

:: 远程仓库
echo ┌─ 远程仓库 ──────────────────────────
git remote -v
echo.

:: 分支信息
echo ┌─ 当前分支 ──────────────────────────
git --no-pager branch -v
echo.

:: 显示详细修改清单
echo ┌─ 本次修改文件清单 ──────────────────
echo.
git --no-pager diff --name-status
git --no-pager diff --cached --name-status 2>nul
echo.
echo ┌─ 修改统计 ──────────────────────────
git --no-pager diff --stat
git --no-pager diff --cached --stat 2>nul
echo.

:: 显示未推送的提交
echo ┌─ 待推送的提交 ──────────────────────
git --no-pager log origin/main..HEAD --oneline 2>nul
if errorlevel 1 (
    echo   （首次推送或无远端分支）
)
echo.

:: 确认操作
set /p CONFIRM=">>> 确认提交并推送? (Y/N): "
if /i not "%CONFIRM%"=="Y" (
    echo 已取消。
    timeout /t 2 >nul
    exit /b
)

:: 输入提交信息
echo.
set /p COMMIT_MSG=">>> 输入提交信息 (回车使用默认): "
if "%COMMIT_MSG%"=="" (
    for /f "tokens=2 delims==" %%a in ('wmic os get localdatetime /value') do set DT=%%a
    set "COMMIT_MSG=更新代码 %DT:~0,4%-%DT:~4,2%-%DT:~6,2% %DT:~8,2%:%DT:~10,2%"
)

echo.
echo ============================================
echo   开始执行
echo ============================================
echo.

echo [1/3] git add -A ...
git add -A

echo [2/3] git commit ...
git commit -m "%COMMIT_MSG%"
if errorlevel 1 (
    echo   [跳过] 无新内容需要提交
    echo.
)

echo [3/3] git push origin main  (最多重试3次)...
set RETRY=0
:RETRY_PUSH
set /a RETRY+=1
git push origin main
if errorlevel 1 goto :PUSH_FAILED
goto :PUSH_OK

:PUSH_FAILED
if !RETRY! LSS 3 (
    echo   [!] 推送失败(!RETRY!/3)，10秒后重试...
    timeout /t 10 /nobreak >nul
    goto RETRY_PUSH
)
echo.
echo ============================================
echo   推送失败！诊断命令：
echo ============================================
echo   1. ping github.com
echo   2. curl -I https://github.com
echo   3. 配置代理：git config --global http.proxy http://127.0.0.1:端口
echo   4. 改用SSH：  git remote set-url origin git@github.com:adminputi/xinzanjinxiaocun.git
echo   5. 手动推送：git push origin main
echo ============================================
pause
exit /b 1

:PUSH_OK

echo.
echo ============================================
echo   推送成功！
echo ============================================
timeout /t 3 >nul
exit /b 0
