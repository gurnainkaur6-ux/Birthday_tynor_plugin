/**
 * scheduler-monitor.js — Real-time scheduler monitoring widget
 * 
 * Features:
 * - Real-time status updates
 * - Progress tracking
 * - Auto-refresh when scheduler is running
 * - Visual status indicators
 * - API integration
 */

class SchedulerMonitor {
    constructor(options = {}) {
        this.containerId = options.containerId || 'scheduler-widget';
        // Portable base URL: prefer an explicit option, then a
        // data-base-url attribute on <body> (set by pages like
        // dashboard/employees.php from the server-side BASE_URL constant),
        // then fall back to a same-directory relative path so the widget
        // still works if neither is present.
        this.baseUrl = options.baseUrl
            || document.body.getAttribute('data-base-url')
            || '';
        this.apiUrl = options.apiUrl || (this.baseUrl ? `${this.baseUrl}/api/scheduler_api.php` : 'api/scheduler_api.php');
        this.detailsUrl = options.detailsUrl || (this.baseUrl ? `${this.baseUrl}/dashboard/scheduler.php` : 'dashboard/scheduler.php');
        this.refreshInterval = options.refreshInterval || 10000; // 10 seconds
        this.autoRefresh = options.autoRefresh !== false;
        this.refreshTimer = null;
        this.container = null;
        
        this.init();
    }
    
    init() {
        this.container = document.getElementById(this.containerId);
        if (!this.container) {
            console.error('Scheduler monitor container not found');
            return;
        }
        
        this.loadStatus();
        
        if (this.autoRefresh) {
            this.startAutoRefresh();
        }
    }
    
    async loadStatus() {
        try {
            const response = await fetch(`${this.apiUrl}?action=status`);
            const data = await response.json();
            
            if (data.success) {
                this.render(data.data);
                
                // If scheduler is running, refresh more frequently
                if (data.data.system_status.scheduler_running) {
                    if (this.refreshInterval > 5000) {
                        this.refreshInterval = 5000; // 5 seconds when running
                        this.restartAutoRefresh();
                    }
                } else {
                    if (this.refreshInterval < 10000) {
                        this.refreshInterval = 10000; // 10 seconds when idle
                        this.restartAutoRefresh();
                    }
                }
            }
        } catch (error) {
            console.error('Failed to load scheduler status:', error);
            this.renderError('Failed to load status');
        }
    }
    
    render(data) {
        const { today_run, today_birthday_count, system_status, next_run } = data;
        
        let html = '<div class="scheduler-widget">';
        
        // Status Header
        html += '<div class="scheduler-widget-header">';
        html += '<div class="scheduler-widget-title">';
        html += '<span class="scheduler-icon">⏰</span>';
        html += '<span>Birthday Scheduler</span>';
        html += '</div>';
        
        // Status Badge
        if (system_status.scheduler_running) {
            html += '<span class="scheduler-status-badge running">Running</span>';
        } else if (today_run && today_run.status === 'completed') {
            html += '<span class="scheduler-status-badge completed">Completed</span>';
        } else if (today_run && today_run.status === 'failed') {
            html += '<span class="scheduler-status-badge failed">Failed</span>';
        } else {
            html += '<span class="scheduler-status-badge idle">Idle</span>';
        }
        
        html += '</div>'; // header
        
        // Today's Status
        if (today_run) {
            html += '<div class="scheduler-widget-body">';
            html += '<div class="scheduler-stats">';
            html += `<div class="scheduler-stat">
                <span class="scheduler-stat-label">Found</span>
                <span class="scheduler-stat-value">${today_run.employees_found || 0}</span>
            </div>`;
            html += `<div class="scheduler-stat">
                <span class="scheduler-stat-label">Sent</span>
                <span class="scheduler-stat-value success">${today_run.emails_sent || 0}</span>
            </div>`;
            html += `<div class="scheduler-stat">
                <span class="scheduler-stat-label">Failed</span>
                <span class="scheduler-stat-value error">${today_run.emails_failed || 0}</span>
            </div>`;
            html += '</div>'; // stats
            
            // Progress Bar
            if (today_run.employees_found > 0 && today_run.status === 'completed') {
                const total = parseInt(today_run.employees_found);
                const sent = parseInt(today_run.emails_sent);
                const percentage = total > 0 ? (sent / total) * 100 : 0;
                
                html += '<div class="scheduler-progress">';
                html += '<div class="scheduler-progress-bar">';
                html += `<div class="scheduler-progress-fill" style="width:${percentage}%"></div>`;
                html += '</div>';
                html += `<div class="scheduler-progress-text">${percentage.toFixed(1)}% success rate</div>`;
                html += '</div>';
            }
            
            html += '</div>'; // body
        } else {
            html += '<div class="scheduler-widget-body">';
            html += '<div class="scheduler-empty">';
            if (today_birthday_count > 0) {
                html += `<p><strong>${today_birthday_count}</strong> birthday${today_birthday_count !== 1 ? 's' : ''} today</p>`;
                html += '<p class="text-muted">Scheduler will run at midnight</p>';
            } else {
                html += '<p>No birthdays today</p>';
            }
            html += '</div>';
            html += '</div>';
        }
        
        // Footer
        html += '<div class="scheduler-widget-footer">';
        
        if (system_status.test_mode) {
            html += '<span class="scheduler-warning">⚠️ Test Mode</span>';
        }
        
        if (!system_status.sending_enabled) {
            html += '<span class="scheduler-error">✗ Sending Disabled</span>';
        }
        
        if (!today_run || today_run.status !== 'running') {
            html += `<span class="scheduler-next">Next: ${next_run.formatted}</span>`;
        }
        
        html += `<a href="${this.detailsUrl}" class="scheduler-link">View Details →</a>`;
        html += '</div>'; // footer
        
        html += '</div>'; // widget
        
        this.container.innerHTML = html;
    }
    
    renderError(message) {
        this.container.innerHTML = `
            <div class="scheduler-widget">
                <div class="scheduler-widget-header">
                    <div class="scheduler-widget-title">
                        <span class="scheduler-icon">⏰</span>
                        <span>Birthday Scheduler</span>
                    </div>
                    <span class="scheduler-status-badge error">Error</span>
                </div>
                <div class="scheduler-widget-body">
                    <div class="scheduler-empty">
                        <p class="scheduler-error">${message}</p>
                    </div>
                </div>
            </div>
        `;
    }
    
    startAutoRefresh() {
        this.stopAutoRefresh();
        this.refreshTimer = setInterval(() => this.loadStatus(), this.refreshInterval);
    }
    
    stopAutoRefresh() {
        if (this.refreshTimer) {
            clearInterval(this.refreshTimer);
            this.refreshTimer = null;
        }
    }
    
    restartAutoRefresh() {
        this.stopAutoRefresh();
        this.startAutoRefresh();
    }
    
    destroy() {
        this.stopAutoRefresh();
        if (this.container) {
            this.container.innerHTML = '';
        }
    }
}

// Auto-initialize if element exists
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('scheduler-widget')) {
        window.schedulerMonitor = new SchedulerMonitor();
    }
});
