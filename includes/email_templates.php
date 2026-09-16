<?php
/**
 * email_templates.php — 10 built-in, responsive HTML birthday email templates.
 *
 * Public API
 * ----------
 *   getBirthdayEmailTemplate(int $id, array $emp): string
 *       $emp keys (all optional except full_name):
 *         full_name, first_name, department_name, designation_name,
 *         dob_formatted, photo_path, current_age, plant_name
 *
 *   getPersonalEmailTemplate(int $id, string $name, string $dept, string $desig): string
 *       Backwards-compatible wrapper used by the cron mailer.
 *
 *   birthdayTemplateCatalog(): array   // [id => 'Name'] for the preview gallery
 */

if (!function_exists('birthdayTemplateCatalog')) {
    function birthdayTemplateCatalog(): array
    {
        return [
            1  => 'Classic Corporate Elegance',
            2  => 'Modern Vibrant Confetti',
            3  => 'Minimalist Executive Gold',
            4  => 'Warm & Friendly Typography',
            5  => 'Tech / Cyber Theme',
            6  => 'Festive Balloon Celebration',
            7  => 'Professional Gradient Wave',
            8  => 'Dark Mode Premium',
            9  => 'Simple Clean Card',
            10 => 'Illustrated Party Banner',
        ];
    }
}

if (!function_exists('bday_company_name')) {
    function bday_company_name(): string
    {
        return defined('COMPANY_NAME') ? COMPANY_NAME : 'The Company';
    }
}

if (!function_exists('bday_initials')) {
    function bday_initials(string $name): string
    {
        $name  = trim($name);
        if ($name === '') return '🎂';
        $parts = preg_split('/\s+/u', $name);
        $a = mb_substr($parts[0] ?? '', 0, 1, 'UTF-8');
        $b = count($parts) > 1 ? mb_substr(end($parts), 0, 1, 'UTF-8') : '';
        return mb_strtoupper($a . $b, 'UTF-8');
    }
}

/**
 * Render a round avatar block — real photo if available, otherwise a
 * coloured initials circle.  Email-client safe (table + inline styles).
 */
if (!function_exists('bday_avatar')) {
    function bday_avatar(array $emp, int $size, string $ring, string $fallbackBg, string $fallbackFg): string
    {
        $photo = trim((string)($emp['photo_path'] ?? ''));
        // Emails need ABSOLUTE image URLs — resolve relative stored paths.
        if ($photo !== '' && function_exists('photo_url')) {
            $photo = photo_url($photo);
        }
        if ($photo !== '') {
            return '<img src="' . htmlspecialchars($photo, ENT_QUOTES) . '" width="' . $size . '" height="' . $size . '" alt="Portrait" '
                 . 'style="width:' . $size . 'px;height:' . $size . 'px;border-radius:50%;object-fit:cover;'
                 . 'border:4px solid ' . $ring . ';display:block;margin:0 auto;">';
        }
        $initials = bday_initials((string)($emp['full_name'] ?? ''));
        $fs = (int)round($size * 0.4);
        return '<div style="width:' . $size . 'px;height:' . $size . 'px;border-radius:50%;background:' . $fallbackBg . ';'
             . 'border:4px solid ' . $ring . ';color:' . $fallbackFg . ';font-size:' . $fs . 'px;font-weight:700;'
             . 'line-height:' . $size . 'px;text-align:center;margin:0 auto;font-family:Arial,Helvetica,sans-serif;">'
             . htmlspecialchars($initials, ENT_QUOTES) . '</div>';
    }
}

/**
 * Main renderer — returns a self-contained responsive HTML block for the
 * chosen template id (1..10) populated with the employee's data.
 */
if (!function_exists('getBirthdayEmailTemplate')) {
    function getBirthdayEmailTemplate(int $id, array $emp): string
    {
        $company = htmlspecialchars(bday_company_name(), ENT_QUOTES);
        $name    = htmlspecialchars(trim((string)($emp['full_name'] ?? 'Team Member')), ENT_QUOTES);
        $dept    = htmlspecialchars(trim((string)($emp['department_name'] ?? '')), ENT_QUOTES);
        $desig   = htmlspecialchars(trim((string)($emp['designation_name'] ?? '')), ENT_QUOTES);
        $dob     = htmlspecialchars(trim((string)($emp['dob_formatted'] ?? '')), ENT_QUOTES);
        $role    = trim(implode(' · ', array_filter([$desig, $dept])));
        $roleTxt = $role !== '' ? $role : 'Valued Team Member';

        switch ($id) {

            // 1 ── Classic Corporate Elegance ────────────────────────────
            case 1:
                $avatar = bday_avatar($emp, 96, '#ffffff', '#1a4fa0', '#ffffff');
                return <<<HTML
<div style="font-family:Georgia,'Times New Roman',serif;max-width:600px;margin:0 auto;background:#f5f7fb;padding:0;border:1px solid #d9e2ef;">
  <div style="background:#0a2240;padding:34px 24px;text-align:center;">
    {$avatar}
    <p style="color:#c9a227;letter-spacing:3px;font-size:12px;margin:16px 0 4px;text-transform:uppercase;">{$company}</p>
    <h1 style="color:#ffffff;font-size:28px;margin:0;">Happy Birthday</h1>
    <p style="color:#dbe4f3;font-size:20px;margin:6px 0 0;">{$name}</p>
  </div>
  <div style="padding:28px 32px;color:#2b3a55;font-size:15px;line-height:1.7;">
    <p>Dear {$name},</p>
    <p>On behalf of the entire team at <strong>{$company}</strong>, we extend our warmest wishes on your birthday. Your dedication as our <strong>{$roleTxt}</strong> is deeply valued.</p>
    <p style="color:#7a889f;">With warm regards,<br>The {$company} Family</p>
  </div>
  <div style="height:6px;background:#c9a227;"></div>
</div>
HTML;

            // 2 ── Modern Vibrant Confetti ───────────────────────────────
            case 2:
                $avatar = bday_avatar($emp, 100, '#ffffff', '#ec4899', '#ffffff');
                return <<<HTML
<div style="font-family:'Segoe UI',Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff0f6;padding:8px;border-radius:18px;">
  <div style="background:linear-gradient(135deg,#f97316,#ec4899,#8b5cf6);border-radius:14px;padding:38px 24px;text-align:center;">
    <div style="font-size:40px;">🎉🎊🎂</div>
    {$avatar}
    <h1 style="color:#ffffff;font-size:30px;margin:16px 0 4px;">Happy Birthday!</h1>
    <p style="color:#ffe4f0;font-size:20px;margin:0;font-weight:600;">{$name}</p>
    <p style="color:#ffffff;opacity:.9;font-size:13px;margin:10px 0 0;">{$roleTxt}</p>
    <p style="color:#ffffff;font-size:15px;margin:18px 0 0;">Wishing you a day bursting with joy from all of us at {$company}!</p>
  </div>
</div>
HTML;

            // 3 ── Minimalist Executive Gold ─────────────────────────────
            case 3:
                $avatar = bday_avatar($emp, 88, '#c9a227', '#111111', '#c9a227');
                return <<<HTML
<div style="font-family:'Helvetica Neue',Arial,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #ececec;padding:44px 32px;text-align:center;">
  {$avatar}
  <p style="letter-spacing:4px;font-size:11px;color:#c9a227;margin:20px 0 6px;text-transform:uppercase;">{$company}</p>
  <h1 style="font-size:26px;font-weight:300;color:#111;margin:0;">Happy Birthday, <strong style="font-weight:600;">{$name}</strong></h1>
  <div style="width:48px;height:2px;background:#c9a227;margin:20px auto;"></div>
  <p style="color:#555;font-size:15px;line-height:1.7;max-width:420px;margin:0 auto;">Here's to another remarkable year. Thank you for your outstanding contribution as {$roleTxt}.</p>
</div>
HTML;

            // 4 ── Warm & Friendly Typography ────────────────────────────
            case 4:
                $avatar = bday_avatar($emp, 92, '#fcd34d', '#b45309', '#ffffff');
                return <<<HTML
<div style="font-family:'Trebuchet MS',Verdana,sans-serif;max-width:600px;margin:0 auto;background:#fffbeb;padding:36px 28px;border-radius:16px;border:2px dashed #f59e0b;text-align:center;">
  {$avatar}
  <h1 style="color:#b45309;font-size:30px;margin:18px 0 4px;">Hooray, {$name}! 🥳</h1>
  <p style="color:#92400e;font-size:16px;margin:0;">It's your special day!</p>
  <p style="color:#78530f;font-size:15px;line-height:1.7;margin:16px auto 0;max-width:440px;">
    Everyone at {$company} wants to wish you the happiest of birthdays. Thanks for being such a wonderful {$roleTxt}. Go enjoy your day — you've earned it!
  </p>
</div>
HTML;

            // 5 ── Tech / Cyber Theme ────────────────────────────────────
            case 5:
                $avatar = bday_avatar($emp, 96, '#22d3ee', '#0f172a', '#22d3ee');
                return <<<HTML
<div style="font-family:'Consolas','Courier New',monospace;max-width:600px;margin:0 auto;background:#0b1220;padding:36px 28px;border:1px solid #1e293b;border-radius:12px;text-align:center;">
  <p style="color:#22d3ee;font-size:12px;letter-spacing:2px;margin:0 0 18px;">&gt; INITIATING birthday.protocol()</p>
  {$avatar}
  <h1 style="color:#e2e8f0;font-size:26px;margin:18px 0 4px;">HAPPY_BIRTHDAY //</h1>
  <p style="color:#22d3ee;font-size:19px;margin:0;">{$name}</p>
  <p style="color:#64748b;font-size:13px;margin:10px 0 0;">[ {$roleTxt} ]</p>
  <p style="color:#94a3b8;font-size:14px;line-height:1.7;margin:18px auto 0;max-width:440px;">System status: celebrating. {$company} wishes you a day of zero bugs and maximum joy.</p>
  <p style="color:#22d3ee;font-size:12px;margin:16px 0 0;">&gt; process complete ✅</p>
</div>
HTML;

            // 6 ── Festive Balloon Celebration ───────────────────────────
            case 6:
                $avatar = bday_avatar($emp, 96, '#ffffff', '#2563eb', '#ffffff');
                return <<<HTML
<div style="font-family:'Segoe UI',Arial,sans-serif;max-width:600px;margin:0 auto;background:#eff6ff;padding:0;border-radius:16px;overflow:hidden;">
  <div style="background:#2563eb;padding:14px;text-align:center;font-size:26px;letter-spacing:8px;">🎈🎈🎈🎈🎈</div>
  <div style="padding:32px 24px;text-align:center;">
    {$avatar}
    <h1 style="color:#1e3a8a;font-size:28px;margin:16px 0 4px;">Happy Birthday, {$name}!</h1>
    <p style="color:#3b82f6;font-size:15px;margin:0;">{$roleTxt}</p>
    <p style="color:#334155;font-size:15px;line-height:1.7;margin:16px auto 0;max-width:440px;">Let's fill the day with balloons, cake and celebration! The whole {$company} team is cheering for you. 🎂🎁</p>
  </div>
  <div style="background:#2563eb;padding:14px;text-align:center;font-size:26px;letter-spacing:8px;">🎉🎉🎉🎉🎉</div>
</div>
HTML;

            // 7 ── Professional Gradient Wave ────────────────────────────
            case 7:
                $avatar = bday_avatar($emp, 100, '#ffffff', '#0ea5e9', '#ffffff');
                return <<<HTML
<div style="font-family:'Segoe UI',Arial,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 8px 24px rgba(2,132,199,.12);">
  <div style="background:linear-gradient(120deg,#0ea5e9,#6366f1);padding:44px 24px 60px;text-align:center;position:relative;">
    {$avatar}
    <h1 style="color:#ffffff;font-size:27px;margin:16px 0 2px;">Happy Birthday, {$name}</h1>
    <p style="color:#e0f2fe;font-size:14px;margin:0;">{$roleTxt}</p>
  </div>
  <div style="height:28px;background:#ffffff;border-radius:50% 50% 0 0;margin-top:-24px;"></div>
  <div style="padding:6px 32px 30px;text-align:center;color:#334155;font-size:15px;line-height:1.7;">
    <p>Warmest wishes from everyone at <strong>{$company}</strong>. May the year ahead bring you continued growth and success.</p>
  </div>
</div>
HTML;

            // 8 ── Dark Mode Premium ─────────────────────────────────────
            case 8:
                $avatar = bday_avatar($emp, 96, '#fbbf24', '#111827', '#fbbf24');
                return <<<HTML
<div style="font-family:'Segoe UI',Arial,sans-serif;max-width:600px;margin:0 auto;background:#111827;padding:40px 30px;border-radius:16px;border-top:4px solid #fbbf24;text-align:center;">
  {$avatar}
  <p style="color:#9ca3af;text-transform:uppercase;letter-spacing:3px;font-size:11px;margin:18px 0 6px;">{$company} celebrates you</p>
  <h1 style="color:#f9fafb;font-size:28px;font-weight:600;margin:0;">Happy Birthday,<br>{$name}</h1>
  <p style="color:#fbbf24;font-size:14px;margin:12px 0 0;">{$roleTxt}</p>
  <hr style="border:0;border-top:1px solid #374151;margin:24px 0;">
  <p style="color:#d1d5db;font-size:15px;line-height:1.7;max-width:440px;margin:0 auto;">May your year ahead be filled with success, joy and wonderful moments.</p>
</div>
HTML;

            // 9 ── Simple Clean Card ─────────────────────────────────────
            case 9:
                $avatar = bday_avatar($emp, 84, '#e5e7eb', '#2563eb', '#ffffff');
                return <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;padding:32px;text-align:center;">
  {$avatar}
  <h1 style="color:#111827;font-size:24px;margin:16px 0 4px;">Happy Birthday, {$name}!</h1>
  <p style="color:#6b7280;font-size:14px;margin:0 0 16px;">{$roleTxt}</p>
  <p style="color:#374151;font-size:15px;line-height:1.7;">Wishing you a wonderful day. Thank you for everything you bring to {$company}.</p>
  <p style="color:#9ca3af;font-size:13px;margin-top:20px;">— The {$company} Team</p>
</div>
HTML;

            // 10 ── Illustrated Party Banner ─────────────────────────────
            default:
                $avatar = bday_avatar($emp, 92, '#ffffff', '#7c3aed', '#ffffff');
                $age    = isset($emp['current_age']) && (int)$emp['current_age'] > 0
                        ? '<p style="color:#6d28d9;font-size:13px;margin:6px 0 0;">Celebrating year ' . (int)$emp['current_age'] . '! 🎉</p>'
                        : '';
                return <<<HTML
<div style="font-family:'Comic Sans MS','Segoe UI',Arial,sans-serif;max-width:600px;margin:0 auto;background:#faf5ff;border-radius:18px;overflow:hidden;border:1px solid #e9d5ff;">
  <div style="background:repeating-linear-gradient(45deg,#a78bfa,#a78bfa 14px,#c4b5fd 14px,#c4b5fd 28px);padding:16px;text-align:center;font-size:26px;">🎈🎂🎁🎉🍰</div>
  <div style="padding:30px 24px;text-align:center;">
    {$avatar}
    <h1 style="color:#6b21a8;font-size:28px;margin:16px 0 4px;">It's a Party for {$name}!</h1>
    <p style="color:#7c3aed;font-size:14px;margin:0;">{$roleTxt}</p>
    {$age}
    <p style="color:#4c1d95;font-size:15px;line-height:1.7;margin:16px auto 0;max-width:440px;">The whole {$company} crew is throwing confetti in your honour. Have the most fantastic birthday ever! 🥳</p>
  </div>
</div>
HTML;
        }
    }
}

/**
 * Backwards-compatible wrapper (old signature).  Maps template ids that
 * fall outside 1..10 into the valid range so the cron never errors.
 */
if (!function_exists('getPersonalEmailTemplate')) {
    function getPersonalEmailTemplate(int $templateId, string $name, string $dept, string $desig): string
    {
        if ($templateId < 1 || $templateId > 10) {
            $templateId = (($templateId - 1) % 10 + 10) % 10 + 1; // wrap into 1..10
        }
        return getBirthdayEmailTemplate($templateId, [
            'full_name'        => $name,
            'department_name'  => $dept,
            'designation_name' => $desig,
        ]);
    }
}
