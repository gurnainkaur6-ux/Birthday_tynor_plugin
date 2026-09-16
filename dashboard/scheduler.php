<?php
/**
 * Single-File Enterprise Email Scheduler System
 * Connected Database: birthday_system
 */

namespace App\Mail;

use PDO;
use PDOException;

// Enable strict error handling to avoid breaking JSON outputs
error_reporting(E_ALL);
ini_set('display_errors', 0); // Hide raw errors from corrupting API output

// 1. EMAIL TEMPLATES CLASS INTEGRATION
class EmailTemplates
{
    public static function personalBirthdayWish(string $employeeName, bool $hasPhotoAttachment = false): string
    {
        $name    = htmlspecialchars($employeeName, ENT_QUOTES, 'UTF-8');
        $company = htmlspecialchars(defined('COMPANY_NAME') ? COMPANY_NAME : 'Tynor', ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:24px;background:#fff8f0;border-radius:12px;border:1px solid #ffe8cc;">
            <h1 style="color:#d9480f;font-size:24px;margin-top:0;">🎉 Happy Birthday, {$name}!</h1>
            <p style="font-size:15px;color:#333;line-height:1.6;">
                Wishing you a fantastic day filled with joy and celebration.
                Everyone at <strong>{$company}</strong> is so glad to have you on the team —
                thank you for everything you bring to work every day.
            </p>
            <p style="font-size:15px;color:#333;">Have a wonderful year ahead! 🎂</p>
            <p style="font-size:13px;color:#888;margin-top:24px;">— The {$company} Team</p>
        </div>
        HTML;
    }

    /**
     * @param array<int, array{full_name: string, department: ?string, designation: ?string}> $birthdayEmployees
     */
    public static function companyAnnouncement(array $birthdayEmployees): string
    {
        $company = htmlspecialchars(defined('COMPANY_NAME') ? COMPANY_NAME : 'Tynor', ENT_QUOTES, 'UTF-8');
        $rows    = '';

        foreach ($birthdayEmployees as $emp) {
            $name     = htmlspecialchars($emp['full_name'],          ENT_QUOTES, 'UTF-8');
            $dept     = htmlspecialchars($emp['department']   ?? '', ENT_QUOTES, 'UTF-8');
            $desig    = htmlspecialchars($emp['designation']  ?? '', ENT_QUOTES, 'UTF-8');
            $roleText = $desig !== '' ? "{$desig}, {$dept}" : $dept;
            $rows    .= "<li style=\"margin-bottom:6px;\"><strong>{$name}</strong> — {$roleText}</li>";
        }

        return <<<HTML
        <div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:24px;background:#f4f5f9;border-radius:12px;border:1px solid #e0e7ff;">
            <h2 style="color:#1d4ed8;margin-top:0;">🎂 Birthdays Today at {$company}</h2>
            <p style="font-size:15px;color:#333;">Let's make their day extra special:</p>
            <ul style="font-size:15px;color:#333;padding-left:20px;">
                {$rows}
            </ul>
            <p style="font-size:13px;color:#888;margin-top:20px;">
                This is an automated notice from the {$company} Birthday Notification System.
            </p>
        </div>
        HTML;
    }
}

// 2. INITIALIZATION & DATABASE CONNECTIONS
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'birthday_system';

// API Handler Router (Ensuring clean JSON without HTML output leakage)
$action = $_REQUEST['action'] ?? null;

try {
    // Step 1: Connect to MySQL Server
    $pdo = new PDO("mysql:host=$dbHost;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Step 2: Auto Create Database if Not Exists
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbName`");

    // Step 3: Auto Create Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `email_schedule_queue` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `recipient_email` VARCHAR(255) NOT NULL,
            `recipient_name` VARCHAR(150) NULL,
            `subject` VARCHAR(255) NOT NULL,
            `template_type` VARCHAR(50) DEFAULT 'custom',
            `body_template` TEXT NOT NULL,
            `scheduled_at` DATETIME NOT NULL,
            `status` ENUM('pending', 'sending', 'sent', 'failed') DEFAULT 'pending',
            `sent_at` DATETIME NULL,
            `execution_logs` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) {
    if ($action) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage(), 'data' => []]);
        exit;
    } else {
        die("Database Error: " . $e->getMessage());
    }
}

// REST API ROUTER
if ($action) {
    if (ob_get_length()) ob_clean(); // Clear buffer to prevent PHP Warnings from breaking JSON
    header('Content-Type: application/json');

    try {
        if ($action === 'get_emails') {
            $stmt = $pdo->query("SELECT * FROM email_schedule_queue ORDER BY scheduled_at DESC");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            exit;
        }

        if ($action === 'render_template') {
            $type = $_GET['type'] ?? 'custom';
            $name = $_GET['name'] ?? 'John Doe';

            if ($type === 'personal') {
                $content = EmailTemplates::personalBirthdayWish($name);
            } elseif ($type === 'announcement') {
                $sampleList = [
                    ['full_name' => $name, 'department' => 'Operations', 'designation' => 'Deputy Manager'],
                    ['full_name' => 'Kinjal Shah', 'department' => 'HR', 'designation' => 'Manager']
                ];
                $content = EmailTemplates::companyAnnouncement($sampleList);
            } else {
                $content = '';
            }

            echo json_encode(['success' => true, 'content' => $content]);
            exit;
        }

        if ($action === 'create_email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $email        = trim($_POST['recipient_email'] ?? '');
            $name         = trim($_POST['recipient_name'] ?? '');
            $subject      = trim($_POST['subject'] ?? '');
            $templateType = trim($_POST['template_type'] ?? 'custom');
            $body         = trim($_POST['body_template'] ?? '');
            $scheduled    = trim($_POST['scheduled_at'] ?? '');

            if (!$email || !$subject || !$scheduled) {
                echo json_encode(['success' => false, 'message' => 'Please fill all required fields!']);
                exit;
            }

            if ($templateType === 'personal') {
                $body = EmailTemplates::personalBirthdayWish($name ?: 'Employee');
            } elseif ($templateType === 'announcement') {
                $body = EmailTemplates::companyAnnouncement([
                    ['full_name' => $name ?: 'Valued Employee', 'department' => 'Team', 'designation' => 'Member']
                ]);
            }

            $stmt = $pdo->prepare("INSERT INTO email_schedule_queue (recipient_email, recipient_name, subject, template_type, body_template, scheduled_at) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$email, $name, $subject, $templateType, $body, $scheduled]);

            echo json_encode(['success' => true, 'message' => 'Email scheduled successfully!']);
            exit;
        }

        if ($action === 'delete_email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM email_schedule_queue WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode(['success' => true, 'message' => 'Scheduled email removed!']);
            exit;
        }

        if ($action === 'run_email_cron') {
            $now = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare("SELECT * FROM email_schedule_queue WHERE status = 'pending' AND scheduled_at <= ? LIMIT 20");
            $stmt->execute([$now]);
            $pendingMails = $stmt->fetchAll();

            $sentCount = 0;
            $failedCount = 0;

            foreach ($pendingMails as $mail) {
                $pdo->prepare("UPDATE email_schedule_queue SET status = 'sending' WHERE id = ?")->execute([$mail['id']]);

                $to = $mail['recipient_email'];
                $subject = $mail['subject'];
                $message = $mail['body_template'];
                
                $headers  = "MIME-Version: 1.0" . "\r\n";
                $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
                $headers .= "From: Birthday Comms <no-reply@tynor.in>" . "\r\n";

                $mailSent = @mail($to, $subject, $message, $headers);

                if ($mailSent) {
                    $log = "Delivered successfully to {$to} at " . date('Y-m-d H:i:s');
                    $pdo->prepare("UPDATE email_schedule_queue SET status = 'sent', sent_at = NOW(), execution_logs = ? WHERE id = ?")
                        ->execute([$log, $mail['id']]);
                    $sentCount++;
                } else {
                    $log = "Delivery failed at " . date('Y-m-d H:i:s');
                    $pdo->prepare("UPDATE email_schedule_queue SET status = 'failed', execution_logs = ? WHERE id = ?")
                        ->execute([$log, $mail['id']]);
                    $failedCount++;
                }
            }

            echo json_encode([
                'success' => true, 
                'sent' => $sentCount, 
                'failed' => $failedCount,
                'processed' => count($pendingMails)
            ]);
            exit;
        }
    } catch (\Throwable $ex) {
        echo json_encode(['success' => false, 'message' => $ex->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard › Scheduler</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        svg { pointer-events: none; }
    </style>
</head>
<body class="bg-[#f8fafc] text-slate-700 font-sans antialiased flex min-h-screen">

<!-- SIDEBAR -->
<aside class="w-64 bg-white border-r border-slate-200 flex-shrink-0 flex flex-col justify-between select-none">
    <div>
        <div class="p-5 flex items-center gap-2.5 text-[#1e56a0] font-bold text-lg">
            <i data-lucide="cake" class="w-5 h-5 text-[#1e56a0]"></i>
            <span>Birthday Comms</span>
        </div>

        <div class="mx-4 mb-4 px-3 py-2 bg-[#eef4ff] border border-[#c7d9f8] rounded-xl flex items-center gap-2 text-[#1e56a0] text-xs font-bold uppercase tracking-wider">
            <i data-lucide="shield-check" class="w-4 h-4"></i>
            <span>Administrator Panel</span>
        </div>

        <nav class="px-3 space-y-5 text-xs font-semibold uppercase tracking-wider text-slate-400">
            <div>
                <p class="px-3 mb-1">Overview</p>
                <div class="space-y-0.5 normal-case tracking-normal text-sm font-medium">
                    <a href="index.php" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-slate-50 text-slate-600">
                        <i data-lucide="layout-dashboard" class="w-4 h-4 text-slate-500"></i> Dashboard
                    </a>
                </div>
            </div>

            <div>
                <p class="px-3 mb-1">People</p>
                <div class="space-y-0.5 normal-case tracking-normal text-sm font-medium">
                    <a href="employees.php" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-slate-50 text-slate-600">
                        <i data-lucide="users" class="w-4 h-4 text-slate-500"></i> Employees
                    </a>
                </div>
            </div>

            <div>
                <p class="px-3 mb-1">Communications</p>
                <div class="space-y-0.5 normal-case tracking-normal text-sm font-medium">
                    <a href="approvals.php" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-slate-50 text-slate-600">
                        <i data-lucide="check-square" class="w-4 h-4 text-slate-500"></i> Approvals
                    </a>
                    <a href="send_notifications.php" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-slate-50 text-slate-600">
                        <i data-lucide="send" class="w-4 h-4 text-slate-500"></i> Send
                    </a>
                    <a href="scheduler.php" class="flex items-center gap-3 px-3 py-2 rounded-lg bg-[#eef4ff] text-[#1e56a0] font-semibold border-l-4 border-[#1e56a0]">
                        <i data-lucide="calendar" class="w-4 h-4 text-[#1e56a0]"></i> Scheduler
                    </a>
                    <a href="logs.php" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-slate-50 text-slate-600">
                        <i data-lucide="clock" class="w-4 h-4 text-slate-500"></i> Send history
                    </a>
                    <a href="analytics.php" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-slate-50 text-slate-600">
                        <i data-lucide="bar-chart-3" class="w-4 h-4 text-slate-500"></i> Analytics
                    </a>
                </div>
            </div>

            <div>
                <p class="px-3 mb-1">Content</p>
                <div class="space-y-0.5 normal-case tracking-normal text-sm font-medium">
                    <a href="preview_email.php" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-slate-50 text-slate-600">
                        <i data-lucide="mail" class="w-4 h-4 text-slate-500"></i> Email templates
                    </a>
                    <a href="channels.php" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-slate-50 text-slate-600">
                        <i data-lucide="radio" class="w-4 h-4 text-slate-500"></i> Channels
                    </a>
                </div>
            </div>

            <div>
                <p class="px-3 mb-1">Governance</p>
                <div class="space-y-0.5 normal-case tracking-normal text-sm font-medium">
                    <a href="users.php" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-slate-50 text-slate-600">
                        <i data-lucide="users-round" class="w-4 h-4 text-slate-500"></i> Users & access
                    </a>
                    <a href="audit_log.php" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-slate-50 text-slate-600">
                        <i data-lucide="shield" class="w-4 h-4 text-slate-500"></i> Audit log
                    </a>
                    <a href="system_health.php" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-slate-50 text-slate-600">
                        <i data-lucide="activity" class="w-4 h-4 text-slate-500"></i> System health
                    </a>
                    <a href="settings.php" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-slate-50 text-slate-600">
                        <i data-lucide="settings" class="w-4 h-4 text-slate-500"></i> Settings
                    </a>
                </div>
            </div>
        </nav>
    </div>
</aside>

<!-- MAIN CONTENT WRAPPER -->
<div class="flex-1 flex flex-col min-w-0">
    <header class="bg-white border-b border-slate-200 px-8 py-3.5 flex justify-between items-center text-sm relative z-10">
        <div class="flex items-center gap-2 text-slate-500">
            <a href="index.php" class="text-[#1e56a0] hover:underline">Dashboard</a>
            <span>›</span>
            <span class="font-semibold text-slate-800">Scheduler</span>
        </div>
        <div class="flex items-center gap-3">
            <button id="btnDispatchCron" type="button" class="bg-slate-100 hover:bg-slate-200 active:bg-slate-300 text-slate-700 px-3.5 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 transition cursor-pointer">
                <i data-lucide="send" class="w-3.5 h-3.5 text-[#1e56a0]"></i> Dispatch Mailer
            </button>
            <button id="btnOpenModal" type="button" class="bg-[#1e56a0] hover:bg-blue-700 active:bg-blue-800 text-white px-3.5 py-1.5 rounded-lg text-xs font-bold flex items-center gap-1.5 transition shadow-sm cursor-pointer">
                <i data-lucide="plus" class="w-3.5 h-3.5"></i> Schedule Email
            </button>
        </div>
    </header>

    <main class="p-8 max-w-6xl w-full mx-auto">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-slate-900">Email Scheduler Workflow</h1>
            <p class="text-sm text-slate-500">Manage and queue automated birthday email dispatches</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Scheduled</p>
                <p id="stat-total" class="text-2xl font-extrabold text-slate-800 mt-1">0</p>
            </div>
            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
                <p class="text-xs font-bold text-amber-500 uppercase tracking-wider">Pending Release</p>
                <p id="stat-pending" class="text-2xl font-extrabold text-slate-800 mt-1">0</p>
            </div>
            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
                <p class="text-xs font-bold text-emerald-500 uppercase tracking-wider">Sent Successfully</p>
                <p id="stat-sent" class="text-2xl font-extrabold text-slate-800 mt-1">0</p>
            </div>
            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
                <p class="text-xs font-bold text-rose-500 uppercase tracking-wider">Failed Releases</p>
                <p id="stat-failed" class="text-2xl font-extrabold text-slate-800 mt-1">0</p>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-xs text-slate-500 uppercase tracking-wider">
                        <th class="p-4 font-semibold">Recipient</th>
                        <th class="p-4 font-semibold">Subject</th>
                        <th class="p-4 font-semibold">Template</th>
                        <th class="p-4 font-semibold">Scheduled Time</th>
                        <th class="p-4 font-semibold">Status</th>
                        <th class="p-4 font-semibold text-right">Action</th>
                    </tr>
                </thead>
                <tbody id="email-table-body" class="divide-y divide-slate-100 text-sm">
                </tbody>
            </table>
        </div>
    </main>
</div>

<!-- Modal Form -->
<div id="emailModal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm hidden flex items-center justify-center p-4 z-50">
    <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl border border-slate-100 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4 pb-3 border-b border-slate-100">
            <h3 class="text-base font-bold text-slate-900 flex items-center gap-2">
                <i data-lucide="calendar-plus" class="text-[#1e56a0] w-5 h-5"></i> Schedule Email Dispatch
            </h3>
            <button id="btnCloseModal" type="button" class="text-slate-400 hover:text-slate-600 transition cursor-pointer">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
        <form id="scheduleEmailForm" onsubmit="handleEmailCreate(event)" class="space-y-4">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">Recipient Name</label>
                    <input type="text" id="recipient_name" name="recipient_name" oninput="handleTemplateChange()" placeholder="Kinjal Shah" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-[#1e56a0] outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">Recipient Email *</label>
                    <input type="email" name="recipient_email" required placeholder="kinjal.s@tynor.in" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-[#1e56a0] outline-none">
                </div>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Email Subject *</label>
                <input type="text" name="subject" required placeholder="Happy Birthday Wish!" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-[#1e56a0] outline-none">
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Dispatch Date & Time *</label>
                <input type="datetime-local" name="scheduled_at" required class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-[#1e56a0] outline-none">
            </div>
            
            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Email Content / Template *</label>
                <select id="template_type" name="template_type" onchange="handleTemplateChange()" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-[#1e56a0] outline-none mb-2 font-medium">
                    <option value="custom">✏️ Custom Text / HTML Body</option>
                    <option value="personal">🎉 Personal Birthday Wish (Class Template)</option>
                    <option value="announcement">🎂 Company Announcement (Class Template)</option>
                </select>

                <textarea id="body_template" name="body_template" rows="4" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-[#1e56a0] outline-none" placeholder="Type custom message..."></textarea>
                
                <div id="template_preview_wrapper" class="hidden mt-2 p-3 border border-slate-200 bg-slate-50 rounded-xl text-xs">
                    <span class="block font-bold text-slate-700 mb-1">Live Template Preview:</span>
                    <div id="template_preview_box" class="bg-white p-2 rounded border border-slate-200"></div>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" id="btnCancelModal" class="px-4 py-2 text-sm text-slate-600 font-semibold hover:bg-slate-100 rounded-lg cursor-pointer">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-[#1e56a0] hover:bg-blue-700 text-white rounded-lg text-sm font-semibold transition cursor-pointer">Queue Dispatch</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        lucide.createIcons();
        loadEmails();

        document.getElementById('btnDispatchCron').addEventListener('click', triggerCronMailer);
        document.getElementById('btnOpenModal').addEventListener('click', () => toggleModal(true));
        document.getElementById('btnCloseModal').addEventListener('click', () => toggleModal(false));
        document.getElementById('btnCancelModal').addEventListener('click', () => toggleModal(false));
    });

    function toggleModal(show) {
        const modal = document.getElementById('emailModal');
        modal.classList.toggle('hidden', !show);
    }

    async function triggerCronMailer() {
        const btn = document.getElementById('btnDispatchCron');
        btn.disabled = true;
        btn.style.opacity = '0.5';
        btn.innerText = 'Dispatching...';

        try {
            const res = await fetch('?action=run_email_cron');
            const data = await res.json();
            if(!data.success) throw new Error(data.message);
            alert(`Mailer Engine Executed!\n\nProcessed: ${data.processed}\nSent: ${data.sent}\nFailed: ${data.failed}`);
            await loadEmails();
        } catch (err) {
            alert('Execution error: ' + err.message);
        } finally {
            btn.disabled = false;
            btn.style.opacity = '1';
            btn.innerHTML = '<i data-lucide="send" class="w-3.5 h-3.5 text-[#1e56a0]"></i> Dispatch Mailer';
            lucide.createIcons();
        }
    }

    async function handleTemplateChange() {
        const type = document.getElementById('template_type').value;
        const name = document.getElementById('recipient_name').value || 'Kinjal Shah';
        const bodyArea = document.getElementById('body_template');
        const previewWrapper = document.getElementById('template_preview_wrapper');
        const previewBox = document.getElementById('template_preview_box');

        if (type === 'custom') {
            bodyArea.classList.remove('hidden');
            bodyArea.required = true;
            previewWrapper.classList.add('hidden');
        } else {
            bodyArea.classList.add('hidden');
            bodyArea.required = false;
            
            const res = await fetch(`?action=render_template&type=${type}&name=${encodeURIComponent(name)}`);
            const data = await res.json();
            
            if (data.success) {
                previewBox.innerHTML = data.content;
                previewWrapper.classList.remove('hidden');
            }
        }
    }

    async function loadEmails() {
        try {
            const res = await fetch('?action=get_emails');
            const resData = await res.json();
            
            if (!resData.success) {
                console.error("Backend Error:", resData.message);
                return;
            }

            const data = resData.data || [];
            const tbody = document.getElementById('email-table-body');
            tbody.innerHTML = '';

            let counts = { total: data.length, pending: 0, sent: 0, failed: 0 };

            data.forEach(item => {
                if(counts[item.status] !== undefined) counts[item.status]++;

                const statusColors = {
                    pending: 'bg-amber-50 text-amber-700 border border-amber-200',
                    sending: 'bg-blue-50 text-blue-700 border border-blue-200',
                    sent: 'bg-emerald-50 text-emerald-700 border border-emerald-200',
                    failed: 'bg-rose-50 text-rose-700 border border-rose-200'
                };

                tbody.innerHTML += `
                    <tr class="hover:bg-slate-50 transition">
                        <td class="p-4">
                            <div class="font-bold text-slate-800">${item.recipient_name || 'N/A'}</div>
                            <div class="text-xs text-slate-500">${item.recipient_email}</div>
                        </td>
                        <td class="p-4 font-semibold text-slate-700">${item.subject}</td>
                        <td class="p-4"><span class="bg-slate-100 text-slate-600 text-xs px-2.5 py-1 rounded-full font-medium capitalize">${item.template_type}</span></td>
                        <td class="p-4 text-slate-500 text-xs">${item.scheduled_at}</td>
                        <td class="p-4">
                            <span class="px-2.5 py-1 rounded-full text-xs font-bold ${statusColors[item.status]}">${item.status.toUpperCase()}</span>
                        </td>
                        <td class="p-4 text-right">
                            <button onclick="deleteEmail(${item.id})" class="text-slate-400 hover:text-rose-600 p-1 transition cursor-pointer">
                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });

            document.getElementById('stat-total').innerText = counts.total;
            document.getElementById('stat-pending').innerText = counts.pending;
            document.getElementById('stat-sent').innerText = counts.sent;
            document.getElementById('stat-failed').innerText = counts.failed;
            
            lucide.createIcons();
        } catch (e) {
            console.error('Fetch Parse Exception:', e);
        }
    }

    async function handleEmailCreate(e) {
        e.preventDefault();
        const formData = new FormData(e.target);
        
        const res = await fetch('?action=create_email', { method: 'POST', body: formData });
        const result = await res.json();

        if (result.success) {
            toggleModal(false);
            e.target.reset();
            handleTemplateChange();
            loadEmails();
        } else {
            alert(result.message);
        }
    }

    async function deleteEmail(id) {
        if(!confirm('Are you sure you want to delete this scheduled task?')) return;
        const formData = new FormData();
        formData.append('id', id);

        await fetch('?action=delete_email', { method: 'POST', body: formData });
        loadEmails();
    }
</script>
</body>
</html>