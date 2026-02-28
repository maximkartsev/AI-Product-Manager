# Effect Studio — Comprehensive UI/UX Design Specification

> Production-ready design for all 10 system modules. Covers admin operational dashboards (light theme) and user-facing flows (dark premium theme). Every page includes layout wireframes, component hierarchy, data flow, interaction patterns, responsive behavior, and connection to other pages.

---

## Table of Contents

1. [Design System Foundation](#1-design-system-foundation)
2. [Navigation Architecture](#2-navigation-architecture)
3. [Admin Pages](#3-admin-pages)
   - 3.1 Economic Engine Dashboard (THE #1 PAGE)
   - 3.2 Bottleneck Monitor
   - 3.3 Provider Management
   - 3.4 Approval Queue
   - 3.5 Enhanced Studio
   - 3.6 Enhanced Workload
   - 3.7 Action-Oriented Logs
4. [User Pages](#4-user-pages)
   - 4.1 Video Submission (/create)
   - 4.2 My Creations (/my-creations)
   - 4.3 Notification Inbox (/notifications)
   - 4.4 Enhanced Effect Gallery (/effects)
   - 4.5 Wallet & Cost Transparency (/wallet)
5. [Shared Components](#5-shared-components)
6. [Cross-Page Navigation Map](#6-cross-page-navigation-map)
7. [Data Refresh Strategy](#7-data-refresh-strategy)
8. [Implementation Priority](#8-implementation-priority)

---

## 1. Design System Foundation

### Dual Theme Architecture

| Surface | Theme | Background | Text | Accent |
|---------|-------|------------|------|--------|
| Admin Panel | Light (oklch) | `oklch(0.985 0 0)` | `oklch(0.145 0 0)` | `oklch(0.205 0 0)` |
| User Pages | Dark Premium | `#05050a` | `#ededed` | `#f97316` (orange) |

### New Design Tokens (add to globals.css)

```css
@theme inline {
  /* Status Colors (admin) */
  --color-status-healthy: oklch(0.72 0.19 142);
  --color-status-warning: oklch(0.75 0.18 70);
  --color-status-critical: oklch(0.63 0.24 29);
  --color-status-info: oklch(0.62 0.19 250);
  --color-status-neutral: oklch(0.55 0.01 250);

  /* Economic Indicators */
  --color-margin-positive: oklch(0.72 0.19 142);
  --color-margin-negative: oklch(0.63 0.24 29);

  /* Dense Panel Surfaces */
  --color-panel-bg: oklch(0.985 0.001 250);
  --color-hud-bg: oklch(0.97 0.003 250);

  /* Table Cell Backgrounds */
  --color-cell-green: oklch(0.95 0.05 142);
  --color-cell-amber: oklch(0.95 0.05 70);
  --color-cell-red: oklch(0.95 0.05 29);
}
```

### New Keyframes (add to globals.css)

```css
@keyframes stage-pulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(236, 72, 153, 0.4); }
  50% { box-shadow: 0 0 0 6px rgba(236, 72, 153, 0); }
}
@keyframes count-fade {
  from { opacity: 0; transform: translateY(4px); }
  to { opacity: 1; transform: translateY(0); }
}
@keyframes notification-slide-in {
  from { opacity: 0; transform: translateX(8px); }
  to { opacity: 1; transform: translateX(0); }
}
```

### CTA Color Strategy

- **Orange (#f97316):** Standard primary actions, interactive states, badges
- **Fuchsia/Violet gradient:** High-value conversion actions (Submit Creation, Add Credits, final pipeline triggers)

### Component Library Already Available

Button (6 variants), Card, Tabs, Dialog, Sheet, DataTable, DataTableToolbar, Select, Input, Checkbox, Tooltip, DropdownMenu, Progress, SmartPagination, SmartFilters, VideoPreviewDialog, HorizontalCarousel, SegmentedToggle, ConfigurableCard, DeleteConfirmDialog, EntityFormSheet, CustomToast

---

## 2. Navigation Architecture

### Admin Sidebar (Enhanced — 5 groups)

```
SIDEBAR (w-56, light theme, border-r)

[Logo]

--- Application ---
  [Sparkles]       Effects
  [FolderOpen]     Categories
  [GitBranch]      Workflows
  [FlaskConical]   Studio              (enhanced)

--- Intelligence --- (NEW GROUP)
  [TrendingUp]     Economics            (badge: margin %)
  [AlertTriangle]  Bottlenecks          (badge: active count)
  [Server]         Providers            (NEW)
  [CheckSquare]    Approval Queue       (badge: pending count)

--- ComfyUI Ops ---
  [Package]        Assets
  [Boxes]          Bundles
  [Ship]           Fleets
  [Trash2]         Cleanup
  [FileSearch]     Asset Audit Logs

--- Platform Ops ---
  [Users]          Users
  [Activity]       Workload            (enhanced)
  [Cpu]            Workers
  [ScrollText]     Logs                (enhanced → action-oriented)
```

Badge indicators: Economics shows blended margin % (green/amber/red), Bottlenecks shows active count (red when >0), Approval Queue shows pending count (blue).

### User Header Menu (Enhanced)

```
Before:  [Menu dropdown]
After:   [Bell(unread dot)] [Menu dropdown]

Menu items:
  - My Videos
  - Effects
  - My Creations    (NEW)
  - Wallet          (NEW)
  - Public Gallery
  - --------
  - Log out
```

---

## 3. Admin Pages

### 3.1 Economic Engine Dashboard — THE #1 PAGE

**Route:** `/admin/economics` (replace existing)
**Purpose:** Command center for platform economics. Answers: "Are we making money? Why is margin changing? What should we do?"

#### Layout

```
┌──────────────────────────────────────────────────────────────────┐
│ MARGIN HUD BAR (sticky top, h-14, bg-hud-bg, border-b)          │
│ [Margin %↑3%] [Burn $/hr] [Revenue $/hr] [Alerts: 3] [1h|24h|7d]│
├────────────────────────────────────┬─────────────────────────────┤
│ PROVIDER COMPARISON MATRIX         │ AI RECOMMENDATIONS           │
│ (col-span-8)                       │ (col-span-4)                 │
│ Table: provider, status, quality,  │ PROVIDER_SWITCH cards        │
│ cost, duration, margin%, volume,   │ PRICE_ADJUSTMENT cards       │
│ success% — all color-coded cells   │ [Approve] [Reject] [Defer]  │
├────────────────────────────────────┼─────────────────────────────┤
│ MARGIN TREND CHART                 │ EXPLORATION BUDGET            │
│ (col-span-8)                       │ (col-span-4)                 │
│ Recharts LineChart, multi-series   │ Epsilon rate progress bar    │
│ per provider, target margin line   │ Exploration spend (24h)      │
│ Time range: [1h][24h][7d][30d]    │ Recent explorations list     │
├────────────────────────────────────┼─────────────────────────────┤
│ COST DRILLDOWN                     │ REVENUE vs COST WATERFALL    │
│ (col-span-7)                       │ (col-span-5)                 │
│ Expandable: Effect → Provider →    │ Recharts BarChart            │
│ Cost Components (compute/partner)  │ revenue(green) - costs(red)  │
└────────────────────────────────────┴─────────────────────────────┘
```

#### Key Components

**MarginHud** — Sticky bar with 5 `<EconomicKpi>` cells:
- Blended Margin (%, trend arrow, sparkline)
- Burn Rate ($/hr)
- Revenue Rate ($/hr)
- Active Alerts (count with warning icon)
- Mini sparklines (1h, 24h, 7d trends)

**Provider Comparison Matrix** — Table with color-coded cells:
- Green: margin >40%, quality >0.8, success >95%
- Amber: margin 20-40%, quality 0.6-0.8, success 85-95%
- Red: margin <20%, quality <0.6, success <85%
- Click row → navigates to Provider Management detail

**AI Recommendations** — Typed cards in scrollable list:
- Types: `PROVIDER_SWITCH`, `PRICE_ADJUSTMENT`, `FLEET_OPTIMIZATION`, `WORKFLOW_TUNING`
- Each shows: confidence %, impact estimate ($+/day), description
- Actions: Approve (primary), Reject (destructive), Defer (outline)

**Margin Trend** — Recharts LineChart:
- Blended margin line (bold, primary color)
- Per-provider lines (thin, provider colors)
- Reference line at target margin (dashed amber)
- Time range tabs: 1h, 24h, 7d, 30d

**Cost Drilldown** — Expandable nested table:
- Group by: Effect or Provider (toggle)
- Expand rows to see: compute cost, partner cost, storage cost
- Hover row highlights corresponding waterfall segment

**Exploration Budget** — Progress bars + list:
- Epsilon rate (0.05 = 5%)
- Exploration spend vs budget (24h)
- Recent exploration results (provider, effect, outcome badge)

#### Data Flow

| Panel | Endpoint | Refresh |
|-------|----------|---------|
| Margin HUD | `GET /api/admin/sse/economic-summary` | SSE real-time |
| Provider Matrix | `GET /api/admin/economics/provider-matrix?window=24h` | 30s poll |
| Recommendations | `GET /api/admin/economics/recommendations?status=pending` | 120s poll |
| Margin Trend | `GET /api/admin/economics/margin-trend?range=24h` | 60s poll |
| Exploration | `GET /api/admin/economics/exploration` | 60s poll |
| Cost Drilldown | `GET /api/admin/economics/cost-drilldown?groupBy=effect` | On demand |
| Waterfall | `GET /api/admin/economics/waterfall?window=24h` | 60s poll |

#### Responsive: >=1440px full grid, 1024-1440px col-span-7+5, <1024px stacked

---

### 3.2 Bottleneck Monitor

**Route:** `/admin/bottlenecks`
**Purpose:** Real-time operational health. Air traffic control status board.

#### Layout

```
┌──────────────────────────────────────────────────────────────────┐
│ STATUS BAR (conditional: green=clear, red=active bottlenecks)    │
│ [● ALL CLEAR] or [● 2 ACTIVE BOTTLENECKS]   Last signal: 14:32 │
├────────────────────┬────────────────────┬────────────────────────┤
│ GPU_SATURATION     │ PROVIDER_LATENCY   │ API_THROTTLING          │
│ Status: CLEAR      │ Status: ACTIVE     │ Status: CLEAR           │
│                    │ Severity: HIGH     │                         │
│                    │ Auto: Rerouted     │                         │
│                    │ [View in Grafana]  │                         │
├────────────────────┼────────────────────┼────────────────────────┤
│ TOKEN_DEPLETION    │ WORKFLOW_INEFF     │ COLD_START_PENALTY      │
│ Status: ACTIVE     │ Status: CLEAR      │ Status: CLEAR           │
│ Severity: MEDIUM   │                    │                         │
│ Auto: Alert sent   │                    │                         │
├────────────────────────────────────┬─────────────────────────────┤
│ SIGNAL DETECTION TIMELINE          │ QUICK ACTIONS                │
│ (col-span-8)                       │ (col-span-4)                 │
│ Chronological feed grouped by      │ Pause Provider for Effect   │
│ 15-min windows, severity badges    │ Adjust Routing Weight slider│
│                                    │ Force Re-evaluate button    │
├────────────────────────────────────┴─────────────────────────────┤
│ BOTTLENECK HISTORY (full width table)                            │
│ timestamp | category | severity | confidence | auto-action |     │
│ resolution | duration | [Grafana link]                           │
└──────────────────────────────────────────────────────────────────┘
```

#### Key Interactions

- **Classification cards:** Left border color (red=HIGH, amber=MEDIUM, blue=LOW), click when active → expand to show affected jobs and timeline
- **Quick Actions:** Inline controls to pause providers, adjust routing weights, force re-evaluation — operators become active managers, not passive observers
- **Grafana links:** Every classification and history row links to pre-filtered Grafana dashboard

#### Data: SSE for real-time status + classifications, 15s poll for signal timeline, 30s for history

---

### 3.3 Provider Management

**Route:** `/admin/providers`
**Purpose:** CRUD + health monitoring for compute providers. Master-detail with slide-out sheets.

#### Layout

```
┌──────────────────────────────────────────────────────────────────┐
│ HEADER: Provider Management                 [+ Add Provider] [↻] │
├───────────────┬───────────────┬───────────────┬──────────────────┤
│ Provider A    │ Provider B    │ Provider C    │ Provider D        │
│ ● Online      │ ● Degraded   │ ● Online      │ ● Offline         │
│ Health: 98%   │ Health: 72%   │ Health: 95%   │ Health: 0%        │
│ Latency: 2.1s │ Latency: 8.3s│ Latency: 1.8s │ Latency: --       │
│ Cost: $0.04   │ Cost: $0.03  │ Cost: $0.05   │ Cost: --          │
├───────────────┴───────────────┴───────────────┴──────────────────┤
│ PROVIDER TABLE (full width, click row → detail sheet)            │
│ ID | Name | Adapter | Capabilities | Effects | Health | Success% │
├──────────────────────────────────────────────────────────────────┤
│                                                                  │
│ DETAIL SHEET (slide from right, w-[600px])                       │
│ Tabs: Overview | Configuration | Workflows | Costs | Health | Runs│
└──────────────────────────────────────────────────────────────────┘
```

#### Add Provider Wizard (Dialog, 4 steps)

1. Select Adapter → radio group with descriptions
2. Configure → dynamic form (endpoint, auth, timeouts)
3. Test Connection → run test job, show latency + output
4. Activate → set routing weight, map effect types, confirm

---

### 3.4 Approval Queue

**Route:** `/admin/approvals`
**Purpose:** Admin review for user-submitted effects after multi-provider AI testing.

#### Layout

```
┌──────────────────────────────────────────────────────────────────┐
│ HEADER: Approval Queue    [Status ▼] [Effect Type ▼] [Date] [✓] │
├──────────┬──────────┬──────────┬─────────────────────────────────┤
│Pending:12│Approved:47│Rejected:3│Avg Review Time: 4m              │
├──────────┴──────────┴──────────┴─────────────────────────────────┤
│ QUEUE LIST (w-[380px])  │  DETAIL PANEL (flex-1)                 │
│ ┌──────────────────────┐│  ┌────────────────────────────────────┐│
│ │ [thumb] Anime Glow   ││  │ ORIGINAL → RESULT (side-by-side)  ││
│ │ @user · 3h ago       ││  │ [video player]  [video player]    ││
│ │ upscale · 0.87 ★     ││  │                                    ││
│ │ ● selected           ││  │ QUALITY SCORES (RadarChart)        ││
│ ├──────────────────────┤│  │ fidelity, artifacts, style,        ││
│ │ [thumb] Retro VHS    ││  │ temporal consistency                ││
│ │ @user · 5h ago       ││  │                                    ││
│ │ style · 0.92 ★       ││  │ PROVIDER COMPARISON (table)        ││
│ ├──────────────────────┤│  │ provider | quality | cost | winner? ││
│ │ ...more items        ││  │                                    ││
│ │                      ││  │ COST BREAKDOWN                     ││
│ │ [Load More]          ││  │ compute + partner + storage         ││
│ │                      ││  │                                    ││
│ │ [Bulk: Approve (3)]  ││  │ [Approve] [Reject] [💬 Feedback]  ││
│ └──────────────────────┘│  └────────────────────────────────────┘│
└──────────────────────────────────────────────────────────────────┘
```

#### Key Features

- **Master-detail split:** Queue list left, detail panel right
- **Radar chart:** Recharts RadarChart showing quality vector dimensions
- **Provider comparison:** Table with green highlight on selected/winner row
- **Bulk mode:** Checkbox per item, sticky bottom bar with Approve/Reject batch buttons
- **Feedback dialog:** Optional text feedback sent to user on reject

---

### 3.5 Enhanced Studio

**Route:** `/admin/studio` (extend existing)

#### New Tabs Added

```
Existing: [Create] [Clone] [Dev Nodes] [Interactive] [Blackbox]
New:      [Create] [Clone] [Dev Nodes] [Interactive] [Blackbox] [Economic Test] [Benchmarks] [A/B Tests]
```

**Economic Test Tab:**
- Select effect + providers (checkbox list) + input video
- Run button → parallel execution across all selected providers
- Results matrix: quality × cost × speed with color-coded cells
- Side-by-side video comparison (one column per provider)

**Benchmarks Tab:**
- Saved benchmark suites (name, effects included, providers, last run)
- Run benchmark → progress → results table + historical chart

**A/B Tests Tab:**
- Create: select 2 providers, traffic split %, duration
- Active tests table: providers, split, remaining time, scores
- Completed tests with statistical significance

---

### 3.6 Enhanced Workload

**Route:** `/admin/workload` (extend existing)

#### New Elements

**Summary cards above table:**
```
[Active Jobs: 24] [Queue Depth: 8] [Avg Duration: 34s] [Error Rate: 1.2%] [⚠ Bottlenecks: 2]
```

**New table columns added to existing matrix:**
- Provider (name)
- Provider Health (StatusDot + health %)
- Bottleneck (BottleneckIndicator badge if active)
- Cost ($ per run)
- Grafana (external link icon)

**Cross-navigation:** Provider name → Provider Management, Bottleneck badge → Bottleneck Monitor, Grafana icon → external dashboard

---

### 3.7 Action-Oriented Logs

**Route:** `/admin/logs` (replace existing audit logs)
**Purpose:** Every log answers: what happened, what's the economic impact, what should the operator do.

#### Layout

```
┌──────────────────────────────────────────────────────────────────┐
│ HEADER: System Logs                                              │
│ [Module ▼] [Severity ▼] [Classification ▼] [Date Range] [Search]│
│                                                         [Live ●] │
├──────────────────────────────────────────────────────────────────┤
│ ⚠ 3 anomalies detected in last hour (AI-flagged) [View]         │
├──────────────────────────────────────────────────────────────────┤
│ Time     │ Module    │ Sev  │ Event Type      │ Impact  │ Action │
│ 14:32:01 │ Economics │ HIGH │ MARGIN_DROP     │ -$42/hr │ ▶Auto  │
│          │           │      │                 │ 15 jobs │ 📋 Run │
│ 14:31:45 │ Provider  │ MED  │ LATENCY_SPIKE   │ 12 jobs │ Monitor│
│ 14:30:12 │ Routing   │ INFO │ POLICY_UPDATED  │ —       │ —      │
│ ... (virtualized infinite scroll)                                │
└──────────────────────────────────────────────────────────────────┘
```

#### Key Features

- **Live/Paused toggle:** SSE streaming when Live, paginated GET when Paused
- **Anomaly banner:** AI-detected unusual patterns highlighted with amber banner
- **Color coding:** HIGH severity rows = red tint, anomaly rows = amber + left border
- **Economic impact:** Red for negative, green for positive, font-mono
- **Auto-action indicator:** Blue Zap icon when system took automatic action
- **Runbook links:** BookOpen icon linking to operational procedures
- **Click event type → cross-navigate** to relevant page (MARGIN_DROP → Economics, PROVIDER_LATENCY → Bottleneck Monitor)

---

## 4. User Pages

### 4.1 Video Submission Page

**Route:** `/create`
**Purpose:** Core funnel — user submits example video to replicate as an AI effect. Must feel magical.

#### Mobile Layout

```
┌──────────────────────────────────────┐
│ [← Back]  LOGO         [🔔] [Menu]  │
├──────────────────────────────────────┤
│                                      │
│   ✦ Create Your                      │
│     AI Effect                        │
│   Turn any viral video into a        │
│   reusable effect, powered by AI.    │
│                                      │
│  ┌──────────────────────────────────┐│
│  │     ☁ UPLOAD ZONE               ││
│  │                                  ││
│  │  Drag & drop your video here     ││
│  │  or click to browse              ││
│  │  MP4, MOV, WEBM up to 100MB     ││
│  │                                  ││
│  │  ──── OR ────                    ││
│  │  ┌──────────────────────────────┐││
│  │  │ [icon] Paste video URL...    │││
│  │  └──────────────────────────────┘││
│  │  Supports TikTok, Instagram, YT ││
│  └──────────────────────────────────┘│
│                                      │
│  ┌──────────────────────────────────┐│
│  │ VIDEO PREVIEW (after upload)     ││
│  │ ┌──────────────────────────────┐ ││
│  │ │  [9:16 video player]         │ ││
│  │ └──────────────────────────────┘ ││
│  │ filename.mp4  |  12MB  |  0:15   ││
│  └──────────────────────────────────┘│
│                                      │
│  ┌──────────────────────────────────┐│
│  │ What do you want from this video?││
│  │ ┌──────────────────────────────┐ ││
│  │ │ e.g. "Make me look like an   │ ││
│  │ │ anime character with glowing │ ││
│  │ │ eyes and dramatic lighting"  │ ││
│  │ └──────────────────────────────┘ ││
│  └──────────────────────────────────┘│
│                                      │
│  ┌──────────────────────────────────┐│
│  │ Style preferences (optional)     ││
│  │ [x] Keep original colors         ││
│  │ [ ] Enhance motion               ││
│  │ [ ] Add particle effects         ││
│  │ [ ] Cinematic lighting           ││
│  └──────────────────────────────────┘│
│                                      │
│  ┌──────────────────────────────────┐│
│  │ Estimated: 50-150 tokens         ││
│  │ Balance: 320 tokens  [Top up]    ││
│  └──────────────────────────────────┘│
│                                      │
│  ┌──────────────────────────────────┐│
│  │  ✦ Create My Effect              ││
│  │  (fuchsia→violet gradient,       ││
│  │   pulse-ring animation)          ││
│  └──────────────────────────────────┘│
└──────────────────────────────────────┘
```

#### Desktop Layout

```
┌────────────────────────────────────────────────────────────────┐
│ [← Back]  LOGO                               [🔔] [Admin] [≡]│
├────────────────────────────────────────────────────────────────┤
│                                                                │
│   ✦ Create Your AI Effect                                      │
│                                                                │
│  ┌──────────────────────────┐  ┌──────────────────────────────┐│
│  │ UPLOAD ZONE              │  │ Description                  ││
│  │ ☁ Drag & drop or browse │  │ ┌──────────────────────────┐ ││
│  │ ──── OR ────             │  │ │ "What do you want..."    │ ││
│  │ [Paste video URL...]     │  │ └──────────────────────────┘ ││
│  │                          │  │                              ││
│  │ VIDEO PREVIEW            │  │ Style preferences            ││
│  │ ┌──────────────────────┐ │  │ [x] Keep colors  [ ] Motion ││
│  │ │ [9:16 player]        │ │  │ [ ] Particles    [ ] Cinema ││
│  │ └──────────────────────┘ │  │                              ││
│  │ file.mp4 | 12MB | 15s   │  │ Cost: 50-150 tokens          ││
│  └──────────────────────────┘  │ Balance: 320 [Top up]       ││
│                                │                              ││
│                                │ [=== Create My Effect ===]   ││
│                                └──────────────────────────────┘│
└────────────────────────────────────────────────────────────────┘
```

#### Animations

- Page entrance: `effects-entrance` staggered d1-d5 per section
- Upload drag-over: border `white/10` → `primary/50`, bg transparent → `white/[0.03]`
- URL platform detection: icon fade-in 150ms (TikTok/Instagram/YouTube icons)
- Submit button: `pulse-ring` 3s infinite, `hover:scale-[1.02]`
- Post-submission: content fades out 300ms → redirect to `/my-creations/[id]`
- Ambient: two glow orbs (fuchsia top-left, violet bottom-right)

#### Error States

- Invalid file: `border-red-500/25 bg-red-500/10` inline error
- File too large: same pattern
- Invalid URL: error below URL input
- Insufficient tokens: amber warning with "Top up tokens" gradient button
- Not authenticated: redirect to auth modal

---

### 4.2 My Creations

**Route:** `/my-creations` (list), `/my-creations/[id]` (detail)
**Purpose:** Track all submissions with real-time 7-stage pipeline status.

#### List View

```
┌──────────────────────────────────────┐
│ ✦ My Creations                       │
│ Track your AI effect submissions     │
│ [By status ▼]  [category | grid]    │
│                                      │
│ ┌──────────────────────────────────┐ │
│ │ [thumb] "Anime Glow Effect"   → │ │
│ │ Stage 4/7 · Evaluating quality   │ │
│ │ [=====>........] 57%             │ │
│ │ 23 tokens · 2 min ago           │ │
│ └──────────────────────────────────┘ │
│                                      │
│ ┌──────────────────────────────────┐ │
│ │ [thumb] "Retro VHS Style"     → │ │
│ │ Stage 7/7 · Published!          │ │
│ │ [====================] 100%     │ │
│ │ 89 tokens · 1 day ago          │ │
│ └──────────────────────────────────┘ │
│                                      │
│ ┌──────────────────────────────────┐ │
│ │ [+] Create New Effect            │ │
│ │ (gradient dashed border)         │ │
│ └──────────────────────────────────┘ │
└──────────────────────────────────────┘
```

#### Detail View — Pipeline Timeline

```
┌──────────────────────────────────────┐
│ "Anime Glow Effect"                  │
│ Submitted 2 hours ago               │
│                                      │
│ ┌────────────────┐┌────────────────┐ │
│ │ Original       ││ Result         │ │
│ │ [video player] ││ [video player] │ │
│ └────────────────┘└────────────────┘ │
│                                      │
│ PIPELINE TIMELINE                    │
│                                      │
│ [✓] Stage 1: Analyzing video        │
│ │   Done in 12s  ·  5 tokens        │
│ │                                    │
│ [✓] Stage 2: Generating prompts     │
│ │   Done in 8s   ·  3 tokens        │
│ │                                    │
│ [✓] Stage 3: Testing providers      │
│ │   Done in 45s  ·  12 tokens       │
│ │   Tested: RunPod, Replicate, λ    │
│ │                                    │
│ [◉] Stage 4: Evaluating quality ← │
│ │   (spinner, pulse animation)      │
│ │   Running for 23s...              │
│ │                                    │
│ [ ] Stage 5: Selecting best result  │
│ │   Pending                          │
│ │                                    │
│ [ ] Stage 6: Awaiting approval      │
│ │   Pending                          │
│ │                                    │
│ [ ] Stage 7: Published!             │
│     Pending                          │
│                                      │
│ ┌──────────────────────────────────┐ │
│ │ Tokens charged: 20 / ~50-150 est │ │
│ │ Remaining: 300  [View wallet →]  │ │
│ └──────────────────────────────────┘ │
│                                      │
│ ┌──────────────────────────────────┐ │
│ │ Quality Score (after Stage 4)    │ │
│ │ Overall: 87%  [════════════>..] │ │
│ │ Visual fidelity:  ★★★★☆ (4/5)  │ │
│ │ Motion accuracy:  ★★★☆☆ (3/5)  │ │
│ │ Style match:      ★★★★★ (5/5)  │ │
│ └──────────────────────────────────┘ │
└──────────────────────────────────────┘
```

#### Animations

- Active stage: `stage-pulse` animation on fuchsia-colored stage icon
- Stage completion: icon transitions spinner → checkmark with `zoom-in-95` 150ms
- Quality bars: animate from 0 to value with 600ms ease-out, staggered 100ms
- Side-by-side preview: slide-in from left (original), slide-in from right (result)

---

### 4.3 Notification Inbox

**Route:** `/notifications`

#### Layout

```
┌──────────────────────────────────────┐
│ 🔔 Notifications      [Mark all read]│
│                                      │
│ --- New ---                          │
│                                      │
│ ┌──────────────────────────────────┐ │
│ │▌[✦ thumb] Your effect is ready!  │ │
│ │ "Anime Glow" finished. Try it!   │ │
│ │ 5 minutes ago                  → │ │
│ └──────────────────────────────────┘ │
│                                      │
│ ┌──────────────────────────────────┐ │
│ │▌[🎨 icon] Published to gallery!  │ │
│ │ "Retro VHS" is live.             │ │
│ │ 2 hours ago                    → │ │
│ └──────────────────────────────────┘ │
│                                      │
│ --- Earlier ---                      │
│                                      │
│ ┌──────────────────────────────────┐ │
│ │ [⚠ icon] Low token balance       │ │
│ │ 15 tokens remaining. Top up.     │ │
│ │ 1 day ago                      → │ │
│ └──────────────────────────────────┘ │
└──────────────────────────────────────┘
```

#### Notification Type Styling

| Type | Left Border | Icon BG |
|------|------------|---------|
| BestResultSelected | `border-l-fuchsia-400` | fuchsia/violet gradient |
| EffectPublished | `border-l-emerald-400` | emerald/teal gradient |
| AdminRejected | `border-l-red-400` | red/orange gradient |
| TokenAlert | `border-l-amber-400` | amber/orange gradient |

#### Bell icon in header: Bell + unread dot (bg-primary, 8px, absolute top-right)

---

### 4.4 Enhanced Effect Gallery

**Route:** `/effects` (modify existing)

#### New Elements

- **AI badge** on auto-generated effects: `bg-gradient fuchsia→violet, text-[9px] font-bold, rounded-full px-2`
- **Attribution:** "by @username" in `text-white/40 text-[10px]`
- **Quality indicator:** Star rating (amber filled, white/15 empty) or percentage
- **Sort dropdown:** Trending (default), Newest, Most Used, Highest Rated, AI Created
- **"Submit your own" CTA card** as last item in grid: dashed gradient border, "+" icon, links to `/create`

---

### 4.5 Wallet & Cost Transparency

**Route:** `/wallet`

#### Layout

```
┌──────────────────────────────────────┐
│ ┌──────────────────────────────────┐ │
│ │ TOKEN BALANCE (gradient border)  │ │
│ │                                  │ │
│ │      320                         │ │
│ │    tokens                        │ │
│ │                                  │ │
│ │  [=== Add Tokens ===]           │ │
│ └──────────────────────────────────┘ │
│                                      │
│ ┌────────┐ ┌────────┐ ┌────────┐   │
│ │  100   │ │  500   │ │  1000  │   │
│ │ tokens │ │ tokens │ │ tokens │   │
│ │ $4.99  │ │ $19.99 │ │ $34.99 │   │
│ │        │ │ POPULAR│ │  BEST  │   │
│ │ [Buy]  │ │ [Buy]  │ │ [Buy]  │   │
│ └────────┘ └────────┘ └────────┘   │
│                                      │
│ Transaction History                  │
│ [Usage | Purchases]                  │
│                                      │
│ --- Today ---                        │
│ Anime Glow Effect        -23 tokens  │
│   ▸ Analysis              -5 tokens  │
│   ▸ Prompts               -3 tokens  │
│   ▸ Testing              -12 tokens  │
│   ▸ Quality               -3 tokens  │
│                                      │
│ --- Yesterday ---                    │
│ Token Purchase          +500 tokens  │
│ Starter Pack              $19.99     │
└──────────────────────────────────────┘
```

#### Transaction Colors

| Type | Color |
|------|-------|
| Purchase (credit) | `text-emerald-400` |
| Job Reserve | `text-amber-400` |
| Job Consume | `text-red-400` |
| Refund | `text-cyan-400` |

#### Key Feature: Expandable per-submission cost breakdown showing which pipeline stage consumed how many tokens

---

## 5. Shared Components (New)

### Admin-Side Domain Components

| Component | Props | Used On |
|-----------|-------|---------|
| `<EconomicKpi>` | label, value, previousValue, unit, trend | Economics HUD, Provider cards |
| `<ProviderHealthCard>` | provider, compact? | Provider Mgmt, Economics, Workload |
| `<BottleneckIndicator>` | category, severity, active | Bottleneck Monitor, Workload |
| `<SeverityBadge>` | severity (HIGH/MED/LOW/INFO) | All admin pages |
| `<StatusDot>` | status (healthy/warning/critical/neutral) | All admin pages |
| `<RecommendationCard>` | type, confidence, impact, onApprove/Reject/Defer | Economics |

### User-Side Components

| Component | Props | Used On |
|-----------|-------|---------|
| `<NotificationBell>` | unreadCount | AppHeader (all pages) |
| `<UploadZone>` | onUpload, onUrlSubmit, accepts | /create |
| `<PipelineTimeline>` | stages[], activeStage | /my-creations/[id] |
| `<TimelineStage>` | label, status, duration, cost, detail | Pipeline |
| `<BalanceCard>` | balance, onTopUp | /wallet |
| `<TransactionRow>` | type, description, amount, expandable | /wallet |
| `<SideBySidePreview>` | originalUrl, resultUrl | /my-creations, /approvals |
| `<QualityScore>` | overall, dimensions[] | /my-creations |
| `<CreationCard>` | title, stage, progress, cost, timeAgo | /my-creations |
| `<SubmitYourOwnCard>` | onClick | /effects grid |
| `<PackageCard>` | tokens, price, badge?, onBuy | /wallet |
| `<NotificationItem>` | type, title, body, timeAgo, read | /notifications |

---

## 6. Cross-Page Navigation Map

```
ADMIN CROSS-NAVIGATION:

Economics Dashboard
  ├── Provider row click ──→ Provider Management (detail sheet)
  ├── Active alerts count ──→ Bottleneck Monitor
  ├── Recommendation (FLEET_OPTIMIZATION) ──→ Fleets page
  └── Cost drilldown effect click ──→ Effects page (filtered)

Bottleneck Monitor
  ├── Signal click (provider) ──→ Provider Management
  ├── Signal click (economic) ──→ Economics Dashboard
  ├── Grafana button ──→ External Grafana
  └── Classification detail ──→ Action-Oriented Logs (filtered)

Provider Management
  ├── Provider executions ──→ Workload (filtered)
  ├── Provider margin ──→ Economics (filtered)
  └── Health issue ──→ Bottleneck Monitor

Approval Queue
  ├── User click ──→ Users page
  └── Provider click ──→ Provider Management

Enhanced Workload
  ├── Provider name ──→ Provider Management
  ├── Bottleneck badge ──→ Bottleneck Monitor
  └── Grafana icon ──→ External Grafana

Action-Oriented Logs
  ├── Module badge ──→ filters to module
  ├── MARGIN_DROP event ──→ Economics Dashboard
  ├── PROVIDER_LATENCY event ──→ Bottleneck Monitor
  └── Runbook link ──→ External runbook

USER CROSS-NAVIGATION:

/create (submit)
  ├── Top up link ──→ /wallet (or PlansModal)
  └── After submit ──→ /my-creations/[id]

/my-creations/[id] (detail)
  ├── View wallet ──→ /wallet
  ├── View in gallery ──→ /effects/[slug]
  └── Create new ──→ /create

/notifications
  ├── BestResultSelected ──→ /my-creations/[id]
  ├── EffectPublished ──→ /effects/[slug]
  ├── AdminRejected ──→ /my-creations/[id]
  └── TokenAlert ──→ /wallet

/effects (gallery)
  └── Submit your own ──→ /create

/wallet
  ├── Transaction (job) ──→ /my-creations/[id]
  └── Buy tokens ──→ PlansModal / Stripe
```

---

## 7. Data Refresh Strategy

| Data Type | Method | Interval | Pages |
|-----------|--------|----------|-------|
| Margin HUD KPIs | SSE | Real-time | Economics |
| Provider health | SSE | Real-time | Providers, Workload |
| Bottleneck classifications | SSE | Real-time | Bottleneck Monitor |
| Live logs | SSE | Real-time | Action Logs |
| Provider matrix | HTTP poll | 30s | Economics |
| Approval queue | HTTP poll | 30s | Approvals |
| AI recommendations | HTTP poll | 120s | Economics |
| Margin trend chart | HTTP poll | 60s | Economics |
| Pipeline status (user) | HTTP poll | 5s (active) | My Creations |
| Notifications (user) | HTTP poll | 30s | Bell icon, Inbox |

SSE endpoints:
- `GET /api/admin/sse/economic-summary`
- `GET /api/admin/sse/bottlenecks`
- `GET /api/admin/sse/provider-health`
- `GET /api/admin/sse/logs`

---

## 8. Implementation Priority

### Phase 1: Admin Economics Core
1. Shared domain components (`EconomicKpi`, `StatusDot`, `SeverityBadge`, `ProviderHealthCard`, `BottleneckIndicator`)
2. **Economic Engine Dashboard** (the #1 page)
3. Provider Management page

### Phase 2: Admin Operational Intelligence
4. Bottleneck Monitor
5. Action-Oriented Logs
6. Enhanced Workload (add columns to existing)

### Phase 3: User Submission Flow
7. `NotificationBell` component (header enhancement)
8. Video Submission page (`/create`)
9. My Creations page (`/my-creations`) with Pipeline Timeline
10. Notification Inbox (`/notifications`)

### Phase 4: User Economics
11. Wallet page (`/wallet`) with transaction breakdown
12. Enhanced Effect Gallery (AI badge, attribution, quality, sort, Submit CTA)

### Phase 5: Admin Advanced
13. Approval Queue
14. Enhanced Studio (Economic Test, Benchmarks, A/B Tests tabs)
