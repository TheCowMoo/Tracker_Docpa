/**
 * DOCPA Tracker Dashboard — Main Application
 *
 * Communicates with the PHP API via fetch().
 * Stores the API key in localStorage.
 */

const API_BASE = window.location.origin;

// State
let state = {
    apiKey: localStorage.getItem('docpa_api_key') || '',
    user: null,
    currentSession: null,
    currentView: 'overview',
    tlSessionId: null,
    tlOffset: 0,
    tlLimit: 50,
    activityChart: null,
    productivityChart: null,
    pollInterval: null,
};

// DOM References
const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => document.querySelectorAll(sel);

const dom = {
    loginScreen: '#login-screen',
    dashboardScreen: '#dashboard-screen',
    loginForm: '#login-form',
    apiKeyInput: '#api-key',
    loginError: '#login-error',
    userBadge: '#user-badge',
    logoutBtn: '#btn-logout',
    statusDot: '#status-dot',
    statusText: '#status-text',
    statStart: '#stat-start',
    statDuration: '#stat-duration',
    statShots: '#stat-shots',
    statMachine: '#stat-machine',
    todayActive: '#today-active',
    todayIdle: '#today-idle',
    todayProductivity: '#today-productivity',
    todayStreak: '#today-streak',
    recentSessions: '#recent-sessions-list',
    tlSessionSelect: '#tl-session-select',
    tlShowIdle: '#tl-show-idle',
    timelineGrid: '#timeline-grid',
    tlPagination: '#tl-pagination',
    reportFrom: '#report-from',
    reportTo: '#report-to',
    reportGroup: '#report-group',
    refreshReport: '#btn-refresh-report',
    reportSummary: '#report-summary',
    toastContainer: '#toast-container',
    navBtns: '.nav-btn',
};

// =============================================================
// Initialization
// =============================================================

function init() {
    // Set up navigation
    $$(dom.navBtns).forEach(btn => {
        btn.addEventListener('click', () => switchView(btn.dataset.view));
    });

    // Login form
    $(dom.loginForm).addEventListener('submit', handleLogin);

    // Logout
    $(dom.logoutBtn).addEventListener('click', handleLogout);

    // Timeline controls
    $(dom.tlSessionSelect).addEventListener('change', (e) => {
        state.tlSessionId = e.target.value;
        state.tlOffset = 0;
        loadTimeline();
    });
    $(dom.tlShowIdle).addEventListener('change', () => {
        state.tlOffset = 0;
        loadTimeline();
    });

    // Report controls
    const today = new Date().toISOString().split('T')[0];
    const weekAgo = new Date(Date.now() - 7 * 86400000).toISOString().split('T')[0];
    $(dom.reportFrom).value = weekAgo;
    $(dom.reportTo).value = today;
    $(dom.refreshReport).addEventListener('click', loadReports);

    // Auto-refresh overview every 10 seconds
    state.pollInterval = setInterval(() => {
        if (state.apiKey && $(dom.dashboardScreen).classList.contains('active')) {
            loadOverview();
        }
    }, 10000);

    // Auto-login if API key exists
    if (state.apiKey) {
        verifyAndLoad();
    }
}

// =============================================================
// API Helpers
// =============================================================

async function api(method, endpoint, body = null, formData = false) {
    const url = `${API_BASE}/api/${endpoint}`;
    const headers = { 'X-API-Key': state.apiKey };
    const opts = { method, headers };

    if (body && !formData) {
        headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
    } else if (body && formData) {
        opts.body = body;
    }

    const res = await fetch(url, opts);
    const data = await res.json();

    if (!res.ok && data.error) {
        throw new Error(data.message || `HTTP ${res.status}`);
    }
    if (data.error) {
        throw new Error(data.message || 'API Error');
    }
    return data;
}

// =============================================================
// Authentication
// =============================================================

async function handleLogin(e) {
    e.preventDefault();
    const apiKey = $(dom.apiKeyInput).value.trim();
    if (!apiKey) return;

    $(dom.loginError).textContent = '';
    const btn = $(dom.loginForm).querySelector('button');
    btn.disabled = true;
    btn.textContent = 'Signing in...';

    try {
        const data = await api('POST', 'auth.php?action=login', { api_key: apiKey });
        state.apiKey = apiKey;
        state.user = data.user;
        localStorage.setItem('docpa_api_key', apiKey);
        showDashboard();
    } catch (err) {
        $(dom.loginError).textContent = err.message;
    } finally {
        btn.disabled = false;
        btn.textContent = 'Sign In';
    }
}

function handleLogout() {
    localStorage.removeItem('docpa_api_key');
    state.apiKey = '';
    state.user = null;
    state.currentSession = null;
    $(dom.loginScreen).classList.add('active');
    $(dom.dashboardScreen).classList.remove('active');
    $(dom.apiKeyInput).value = '';
    clearInterval(state.pollInterval);
}

async function verifyAndLoad() {
    try {
        const data = await api('GET', 'auth.php?action=verify');
        state.user = data.user;
        showDashboard();
    } catch {
        // Key is invalid — stay on login
        localStorage.removeItem('docpa_api_key');
        state.apiKey = '';
    }
}

function showDashboard() {
    $(dom.loginScreen).classList.remove('active');
    $(dom.dashboardScreen).classList.add('active');
    $(dom.userBadge).textContent = state.user.display_name || state.user.username;

    loadOverview();
    loadSessionOptions();
    loadReports();
}

// =============================================================
// Navigation
// =============================================================

function switchView(view) {
    state.currentView = view;
    $$(dom.navBtns).forEach(b => b.classList.toggle('active', b.dataset.view === view));
    $$('.view').forEach(v => v.classList.toggle('active', v.id === `view-${view}`));

    if (view === 'timeline') loadTimeline();
    if (view === 'reports') loadReports();
}

// =============================================================
// Overview
// =============================================================

async function loadOverview() {
    try {
        // Current session
        const sessionData = await api('GET', 'sessions.php?action=current');
        state.currentSession = sessionData.session;

        const statusCard = $('#status-card');
        if (sessionData.session) {
            const s = sessionData.session;
            const isActive = s.status === 'active';
            $(dom.statusDot).className = `status-dot ${isActive ? 'active' : 'idle'}`;
            $(dom.statusText).textContent = isActive ? 'Active — Tracking' : 'Idle — Paused';
            $(dom.statStart).textContent = formatTime(s.start_time);
            $(dom.statDuration).textContent = formatDuration(s.elapsed_seconds || 0);
            $(dom.statShots).textContent = s.screenshot_count || 0;
            $(dom.statMachine).textContent = s.machine_name || '--';
        } else {
            $(dom.statusDot).className = 'status-dot offline';
            $(dom.statusText).textContent = 'Offline — No active session';
            $(dom.statStart).textContent = '--';
            $(dom.statDuration).textContent = '--';
            $(dom.statShots).textContent = '--';
            $(dom.statMachine).textContent = '--';
        }

        // Today's stats
        const today = new Date().toISOString().split('T')[0];
        const statsData = await api('GET', `stats.php?from=${today}&to=${today}`);
        const t = statsData.totals || {};

        $(dom.todayActive).textContent = Math.round((t.total_active_seconds || 0) / 60);
        $(dom.todayIdle).textContent = Math.round((t.total_idle_seconds || 0) / 60);
        $(dom.todayProductivity).textContent = (t.productivity_score || 0) + '%';
        $(dom.todayStreak).textContent = t.current_streak_days || 0;

        // Recent sessions
        const sessionsData = await api('GET', 'sessions.php?limit=10');
        renderRecentSessions(sessionsData.sessions || []);

    } catch (err) {
        console.error('Overview error:', err);
    }
}

function renderRecentSessions(sessions) {
    const container = $(dom.recentSessions);
    if (!sessions.length) {
        container.innerHTML = '<p class="muted">No sessions yet.</p>';
        return;
    }

    container.innerHTML = sessions.map(s => `
        <div class="session-item">
            <div>
                <div>${formatTime(s.start_time)}</div>
                <div class="muted">${s.screenshot_count || 0} shots · ${formatDuration(s.total_active_seconds || 0)} active</div>
            </div>
            <span class="session-status ${s.status}">${s.status}</span>
        </div>
    `).join('');
}

// =============================================================
// Timeline
// =============================================================

async function loadSessionOptions() {
    try {
        const data = await api('GET', 'sessions.php?limit=100');
        const select = $(dom.tlSessionSelect);
        select.innerHTML = '<option value="">-- Select a session --</option>';

        (data.sessions || []).forEach(s => {
            const opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = `${formatTime(s.start_time)} — ${s.status} (${s.screenshot_count || 0} shots)`;
            select.appendChild(opt);
        });

        // Auto-select current session
        if (state.tlSessionId) {
            select.value = state.tlSessionId;
        }
    } catch (err) {
        console.error('Load session options error:', err);
    }
}

async function loadTimeline() {
    const grid = $(dom.timelineGrid);
    const pagination = $(dom.tlPagination);

    if (!state.tlSessionId) {
        grid.innerHTML = '<p class="muted">Select a session to view screenshots.</p>';
        pagination.innerHTML = '';
        return;
    }

    try {
        const showIdle = $(dom.tlShowIdle).checked ? '' : '&is_idle=0';
        const data = await api('GET', `screenshots.php?session_id=${state.tlSessionId}&limit=${state.tlLimit}&offset=${state.tlOffset}${showIdle}`);

        const shots = data.screenshots || [];

        if (!shots.length) {
            grid.innerHTML = '<p class="muted">No screenshots in this session.</p>';
            pagination.innerHTML = '';
            return;
        }

        grid.innerHTML = shots.map(sc => `
            <div class="timeline-item ${sc.is_idle ? 'idle' : ''}" onclick="showLightbox('${sc.image_url}')">
                <img src="${sc.thumbnail_url || sc.image_url}" alt="Screenshot" loading="lazy">
                <div class="tl-info">
                    <span>${formatTime(sc.captured_at)}</span>
                    ${sc.is_idle ? '<span class="tl-idle-badge">Idle</span>' : ''}
                </div>
            </div>
        `).join('');

        // Pagination
        const total = data.total || 0;
        const totalPages = Math.ceil(total / state.tlLimit);
        const currentPage = Math.floor(state.tlOffset / state.tlLimit) + 1;

        if (totalPages <= 1) {
            pagination.innerHTML = '';
        } else {
            let html = '';
            for (let i = 1; i <= totalPages && i <= 10; i++) {
                html += `<button class="btn btn-sm ${i === currentPage ? 'btn-primary' : 'btn-outline'}" onclick="goToTimelinePage(${i})">${i}</button>`;
            }
            pagination.innerHTML = html;
        }

    } catch (err) {
        grid.innerHTML = `<p class="muted">Error loading screenshots: ${err.message}</p>`;
    }
}

window.goToTimelinePage = function(page) {
    state.tlOffset = (page - 1) * state.tlLimit;
    loadTimeline();
};

// =============================================================
// Reports
// =============================================================

let activityChart = null;
let productivityChart = null;

async function loadReports() {
    const from = $(dom.reportFrom).value;
    const to = $(dom.reportTo).value;
    const group = $(dom.reportGroup).value;

    try {
        const data = await api('GET', `stats.php?from=${from}&to=${to}&group_by=${group}`);
        const sessions = data.sessions || [];
        const totals = data.totals || {};

        // Build chart datasets
        const labels = sessions.map(s => s.period);
        const activeMin = sessions.map(s => Math.round((s.total_active_seconds || 0) / 60));
        const idleMin = sessions.map(s => Math.round((s.total_idle_seconds || 0) / 60));

        // Activity chart
        const actCtx = document.getElementById('chart-activity').getContext('2d');
        if (activityChart) activityChart.destroy();
        activityChart = new Chart(actCtx, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    { label: 'Active (min)', data: activeMin, backgroundColor: 'rgba(52,211,153,0.6)', borderColor: '#34d399', borderWidth: 1 },
                    { label: 'Idle (min)', data: idleMin, backgroundColor: 'rgba(245,158,11,0.6)', borderColor: '#f59e0b', borderWidth: 1 },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#9aa0b0' } } },
                scales: {
                    x: { ticks: { color: '#9aa0b0' }, grid: { color: '#2d3142' } },
                    y: { stacked: true, ticks: { color: '#9aa0b0' }, grid: { color: '#2d3142' } },
                },
            },
        });

        // Productivity chart (screenshot ratio)
        const prodCtx = document.getElementById('chart-productivity').getContext('2d');
        if (productivityChart) productivityChart.destroy();
        productivityChart = new Chart(prodCtx, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'Productivity %',
                    data: sessions.map(s => {
                        const shots = (data.screenshots || []).find(sh => sh.period === s.period);
                        if (!shots) return 0;
                        const total = (shots.active_shots || 0) + (shots.idle_shots || 0);
                        return total > 0 ? Math.round((shots.active_shots / total) * 100) : 0;
                    }),
                    borderColor: '#4f7cff',
                    backgroundColor: 'rgba(79,124,255,0.1)',
                    fill: true,
                    tension: 0.3,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#9aa0b0' } } },
                scales: {
                    x: { ticks: { color: '#9aa0b0' }, grid: { color: '#2d3142' } },
                    y: { min: 0, max: 100, ticks: { color: '#9aa0b0', callback: v => v + '%' }, grid: { color: '#2d3142' } },
                },
            },
        });

        // Summary
        $(dom.reportSummary).innerHTML = `
            <div class="summary-item">
                <span class="summary-value">${totals.total_sessions || 0}</span>
                <span class="summary-label">Sessions</span>
            </div>
            <div class="summary-item">
                <span class="summary-value">${Math.round((totals.total_active_seconds || 0) / 60)}</span>
                <span class="summary-label">Active Min</span>
            </div>
            <div class="summary-item">
                <span class="summary-value">${Math.round((totals.total_idle_seconds || 0) / 60)}</span>
                <span class="summary-label">Idle Min</span>
            </div>
            <div class="summary-item">
                <span class="summary-value">${totals.productivity_score || 0}%</span>
                <span class="summary-label">Productivity</span>
            </div>
            <div class="summary-item">
                <span class="summary-value">${totals.avg_active_minutes_per_day || 0}</span>
                <span class="summary-label">Avg Min/Day</span>
            </div>
            <div class="summary-item">
                <span class="summary-value">${totals.current_streak_days || 0}</span>
                <span class="summary-label">Day Streak</span>
            </div>
        `;

    } catch (err) {
        console.error('Reports error:', err);
        showToast(err.message, 'error');
    }
}

// =============================================================
// Lightbox
// =============================================================

window.showLightbox = function(url) {
    const lb = document.createElement('div');
    lb.className = 'lightbox';
    lb.onclick = () => lb.remove();
    const img = document.createElement('img');
    img.src = url;
    lb.appendChild(img);
    document.body.appendChild(lb);
};

// =============================================================
// Utilities
// =============================================================

function formatTime(dt) {
    if (!dt) return '--';
    const d = new Date(dt);
    return d.toLocaleString(undefined, {
        month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

function formatDuration(seconds) {
    if (!seconds || seconds <= 0) return '0m';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    if (h > 0) return `${h}h ${m}m`;
    return `${m}m`;
}

function showToast(message, type = 'info') {
    const container = $(dom.toastContainer);
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
}

// =============================================================
// Boot
// =============================================================

document.addEventListener('DOMContentLoaded', init);