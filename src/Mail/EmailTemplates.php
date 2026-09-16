<?php
/**
 * Single-File Blue & White Email Scheduler Engine with Template Class Integration
 * -------------------------------------------------------------------------------
 * Architecture: PHP PDO, Dynamic Email Templates, REST API Handler,
 *               Self-Healing Database, Clean Blue/White UI.
 */

namespace App\Mail;

use PDO;
use PDOException;

// 1. EMAIL TEMPLATES CLASS INTEGRATION
class EmailTemplates
{
    public static function personalBirthdayWish(string $employeeName, bool $hasPhotoAttachment = false): string
    {
        $name    = htmlspecialchars($employeeName, ENT_QUOTES, 'UTF-8');
        $company = htmlspecialchars(defined('COMPANY_NAME') ? COMPANY_NAME : 'The Company', ENT_QUOTES, 'UTF-8');

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
        $company = htmlspecialchars(defined('COMPANY_NAME') ? COMPANY_NAME : 'The Company', ENT_QUOTES, 'UTF-8');
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
            <h2 style="color:#4f46e5;margin-top:0;">🎂 Birthdays Today at {$company}</h2>
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

// Global System Setup
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'tynor_db'; 

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// AUTOMATIC DB MIGRATION
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

// REST API ROUTER
$action = $_REQUEST['action'] ?? null;

if ($action) {
    header('Content-Type: application/json');

    // Fetch Emails
    if ($action === 'get_emails') {
        $stmt = $pdo->query("SELECT * FROM email_schedule_queue ORDER BY scheduled_at DESC");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        exit;
    }

    // Generate Preview Content dynamically via AJAX for template selection
    if ($action === 'render_template') {
        $type = $_GET['type'] ?? 'custom';
        $name = $_GET['name'] ?? 'John Doe';

        if ($type === 'personal') {
            $content = EmailTemplates::personalBirthdayWish($name);
        } elseif ($type === 'announcement') {
            $sampleList = [
                ['full_name' => $name, 'department' => 'Engineering', 'designation' => 'Sr. Developer'],
                ['full_name' => 'Sarah Connor', 'department' => 'Operations', 'designation' => 'Team Lead']
            ];
            $content = EmailTemplates::companyAnnouncement($sampleList);
        } else {
            $content = '';
        }

        echo json_encode(['success' => true, 'content' => $content]);
        exit;
    }

    // Schedule New Email
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

        // If template chosen, render final HTML to store or fall back to typed body
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

    // Delete Scheduled Email
    if ($action === 'delete_email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM email_schedule_queue WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode(['success' => true, 'message' => 'Scheduled email removed!']);
        exit;
    }

    // CRON MAILER DISPATCHER
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
            $headers .= "From: Birthday Comms <no-reply@yourdomain.com>" . "\r\n";

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
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Scheduler System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-blue-50/50 text-blue-950 font-sans antialiased min-h-screen">

<!-- Top Navigation -->
<header class="bg-blue-600 text-white shadow-md">
    <div class="max-w-6xl mx-auto px-6 py-4 flex justify-between items-center">
        <div class="flex items-center gap-3">
            <div class="bg-white/10 p-2 rounded-lg backdrop-blur-sm">
                <i data-lucide="mail-check" class="w-6 h-6 text-white"></i>
            </div>
            <div>
                <h1 class="text-xl font-bold tracking-tight">Email Scheduler Engine</h1>
                <p class="text-xs text-blue-100">Template Supported Enterprise Edition</p>
            </div>
        </div>
        <div class="flex gap-3">
            <button onclick="triggerCronMailer()" class="bg-blue-700 hover:bg-blue-800 border border-blue-500 text-white px-4 py-2 rounded-lg text-sm font-semibold flex items-center gap-2 transition shadow-sm">
                <i data-lucide="send" class="w-4 h-4 text-blue-200"></i> Run Mailer
            </button>
            <button onclick="toggleModal(true)" class="bg-white hover:bg-blue-50 text-blue-700 px-4 py-2 rounded-lg text-sm font-bold flex items-center gap-2 transition shadow-md">
                <i data-lucide="plus" class="w-4 h-4 text-blue-700"></i> Schedule Email
            </button>
        </div>
    </div>
</header>

<div class="max-w-6xl mx-auto p-6">
    <!-- Stat Badges -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-5 mb-8">
        <div class="bg-white p-5 rounded-2xl shadow-sm border border-blue-100">
            <div class="flex justify-between items-center text-blue-600 mb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-blue-400">Total Queued</span>
                <i data-lucide="layers" class="w-5 h-5"></i>
            </div>
            <p id="stat-total" class="text-3xl font-extrabold text-blue-950">0</p>
        </div>
        <div class="bg-white p-5 rounded-2xl shadow-sm border border-blue-100">
            <div class="flex justify-between items-center text-blue-600 mb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-amber-500">Pending Release</span>
                <i data-lucide="clock" class="w-5 h-5 text-amber-500"></i>
            </div>
            <p id="stat-pending" class="text-3xl font-extrabold text-blue-950">0</p>
        </div>
        <div class="bg-white p-5 rounded-2xl shadow-sm border border-blue-100">
            <div class="flex justify-between items-center text-blue-600 mb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-blue-600">Successfully Sent</span>
                <i data-lucide="check-circle-2" class="w-5 h-5 text-blue-600"></i>
            </div>
            <p id="stat-sent" class="text-3xl font-extrabold text-blue-950">0</p>
        </div>
        <div class="bg-white p-5 rounded-2xl shadow-sm border border-blue-100">
            <div class="flex justify-between items-center text-blue-600 mb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-rose-500">Failed Dispatches</span>
                <i data-lucide="alert-triangle" class="w-5 h-5 text-rose-500"></i>
            </div>
            <p id="stat-failed" class="text-3xl font-extrabold text-blue-950">0</p>
        </div>
    </div>

    <!-- Data Table -->
    <div class="bg-white rounded-2xl shadow-sm border border-blue-100 overflow-hidden">
        <div class="p-4 bg-white border-b border-blue-50 flex justify-between items-center">
            <h2 class="font-bold text-blue-950 text-base flex items-center gap-2">
                <i data-lucide="list" class="w-4 h-4 text-blue-600"></i> Scheduled Email Queue
            </h2>
            <button onclick="loadEmails()" class="text-xs text-blue-600 font-semibold hover:underline flex items-center gap-1">
                <i data-lucide="refresh-cw" class="w-3 h-3"></i> Refresh List
            </button>
        </div>
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-blue-50/60 text-blue-900 text-xs uppercase tracking-wider border-b border-blue-100">
                    <th class="p-4">Recipient</th>
                    <th class="p-4">Subject</th>
                    <th class="p-4">Template</th>
                    <th class="p-4">Dispatch Time</th>
                    <th class="p-4">Status</th>
                    <th class="p-4 text-right">Action</th>
                </tr>
            </thead>
            <tbody id="email-table-body" class="divide-y divide-blue-50 text-sm">
                <!-- JS Population -->
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Form -->
<div id="emailModal" class="fixed inset-0 bg-blue-950/40 backdrop-blur-sm hidden flex items-center justify-center p-4 z-50">
    <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl border border-blue-100 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-5 pb-3 border-b border-blue-50">
            <h3 class="text-lg font-bold text-blue-950 flex items-center gap-2">
                <i data-lucide="calendar-plus" class="text-blue-600 w-5 h-5"></i> Schedule Email Task
            </h3>
            <button onclick="toggleModal(false)" class="text-blue-400 hover:text-blue-600 transition">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
        <form id="scheduleEmailForm" onsubmit="handleEmailCreate(event)" class="space-y-4">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-blue-900 mb-1">Recipient Name</label>
                    <input type="text" id="recipient_name" name="recipient_name" oninput="handleTemplateChange()" placeholder="John Doe" class="w-full border border-blue-200 rounded-xl p-2.5 text-sm focus:ring-2 focus:ring-blue-600 outline-none bg-blue-50/20">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-blue-900 mb-1">Recipient Email *</label>
                    <input type="email" name="recipient_email" required placeholder="john@example.com" class="w-full border border-blue-200 rounded-xl p-2.5 text-sm focus:ring-2 focus:ring-blue-600 outline-none bg-blue-50/20">
                </div>
            </div>
            <div>
                <label class="block text-xs font-semibold text-blue-900 mb-1">Email Subject *</label>
                <input type="text" name="subject" required placeholder="Happy Birthday Wish!" class="w-full border border-blue-200 rounded-xl p-2.5 text-sm focus:ring-2 focus:ring-blue-600 outline-none bg-blue-50/20">
            </div>
            <div>
                <label class="block text-xs font-semibold text-blue-900 mb-1">Dispatch Date & Time *</label>
                <input type="datetime-local" name="scheduled_at" required class="w-full border border-blue-200 rounded-xl p-2.5 text-sm focus:ring-2 focus:ring-blue-600 outline-none bg-blue-50/20">
            </div>
            
            <!-- Email Template Dropdown & Feature -->
            <div>
                <label class="block text-xs font-semibold text-blue-900 mb-1">Email Content / Template *</label>
                <select id="template_type" name="template_type" onchange="handleTemplateChange()" class="w-full border border-blue-200 rounded-xl p-2.5 text-sm focus:ring-2 focus:ring-blue-600 outline-none bg-blue-50/20 font-medium text-blue-950 mb-2">
                    <option value="custom">✏️ Custom HTML / Plain Text Message</option>
                    <option value="personal">🎉 Personal Birthday Wish (Pre-built Template)</option>
                    <option value="announcement">🎂 Company Announcement (Pre-built Template)</option>
                </select>

                <!-- Custom HTML Body Input Field -->
                <textarea id="body_template" name="body_template" rows="4" class="w-full border border-blue-200 rounded-xl p-2.5 text-sm focus:ring-2 focus:ring-blue-600 outline-none bg-blue-50/20" placeholder="Type your custom HTML or text email body here..."></textarea>
                
                <!-- Dynamic Template HTML Preview Box -->
                <div id="template_preview_wrapper" class="hidden mt-2 p-3 border border-blue-100 bg-slate-50 rounded-xl text-xs">
                    <span class="block font-bold text-blue-900 mb-1">Live Template Preview:</span>
                    <div id="template_preview_box" class="bg-white p-2 rounded border border-slate-200"></div>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-3">
                <button type="button" onclick="toggleModal(false)" class="px-4 py-2 text-sm text-blue-600 font-semibold hover:bg-blue-50 rounded-xl">Cancel</button>
                <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-sm font-semibold transition shadow-md">Queue Dispatch</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        lucide.createIcons();
        loadEmails();
    });

    function toggleModal(show) {
        document.getElementById('emailModal').classList.toggle('hidden', !show);
    }

    async function handleTemplateChange() {
        const type = document.getElementById('template_type').value;
        const name = document.getElementById('recipient_name').value || 'John Doe';
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
            
            // Fetch Rendered HTML Preview from Backend Class
            const res = await fetch(`?action=render_template&type=${type}&name=${encodeURIComponent(name)}`);
            const data = await res.json();
            
            if (data.success) {
                previewBox.innerHTML = data.content;
                previewWrapper.classList.remove('hidden');
            }
        }
    }

    async function loadEmails() {
        const res = await fetch('?action=get_emails');
        const { data } = await res.json();
        
        const tbody = document.getElementById('email-table-body');
        tbody.innerHTML = '';

        let counts = { total: data.length, pending: 0, sent: 0, failed: 0 };

        data.forEach(item => {
            if(counts[item.status] !== undefined) counts[item.status]++;

            const statusColors = {
                pending: 'bg-amber-50 text-amber-700 border border-amber-200',
                sending: 'bg-blue-50 text-blue-700 border border-blue-200',
                sent: 'bg-blue-600 text-white font-semibold',
                failed: 'bg-rose-50 text-rose-700 border border-rose-200'
            };

            tbody.innerHTML += `
                <tr class="hover:bg-blue-50/30 transition">
                    <td class="p-4">
                        <div class="font-bold text-blue-950">${item.recipient_name || 'N/A'}</div>
                        <div class="text-xs text-blue-500 font-medium">${item.recipient_email}</div>
                    </td>
                    <td class="p-4 font-semibold text-blue-900">${item.subject}</td>
                    <td class="p-4"><span class="bg-blue-100 text-blue-800 text-xs px-2.5 py-1 rounded-full font-medium capitalize">${item.template_type}</span></td>
                    <td class="p-4 text-blue-600 text-xs font-medium">${item.scheduled_at}</td>
                    <td class="p-4">
                        <span class="px-3 py-1 rounded-full text-xs ${statusColors[item.status]}">${item.status.toUpperCase()}</span>
                    </td>
                    <td class="p-4 text-right">
                        <button onclick="deleteEmail(${item.id})" class="text-blue-300 hover:text-rose-600 p-1 transition" title="Delete Task">
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
        if(!confirm('Are you sure you want to remove this scheduled task?')) return;
        const formData = new FormData();
        formData.append('id', id);

        await fetch('?action=delete_email', { method: 'POST', body: formData });
        loadEmails();
    }

    async function triggerCronMailer() {
        const res = await fetch('?action=run_email_cron');
        const data = await res.json();
        alert(`Mailer Dispatched!\nProcessed: ${data.processed}\nSent: ${data.sent}\nFailed: ${data.failed}`);
        loadEmails();
    }
</script>
</body>
</html>