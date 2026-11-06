<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ match ($allowedScreens[0]) {
        'onboard' => '商户入驻向导 · 静态展示',
        'merchant' => '编辑商户 · 静态展示',
        default => '编辑支付方式 · 静态展示',
    } }}</title>
    <style>
@verbatim
        :root {
            --amber: #d97706;
            --amber-hover: #b45309;
            --ink: #111827;
            --muted: #6b7280;
            --line: #e5e7eb;
            --page: #f9fafb;
            --card: #fff;
            --danger: #ef4444;
            --sidebar: 16.5rem;
            --topbar: 4rem;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            height: 100%;
            color: var(--ink);
            background: var(--page);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", "PingFang SC", "Noto Sans SC", sans-serif;
            font-size: 14px;
            line-height: 1.5;
        }

        button, input, select, textarea { font: inherit; color: inherit; }

        .shell { display: flex; height: 100vh; }

        .sidebar {
            width: var(--sidebar);
            flex: none;
            display: flex;
            flex-direction: column;
            background: var(--card);
            border-right: 1px solid var(--line);
        }

        .brand {
            height: var(--topbar);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0 1.25rem;
            border-bottom: 1px solid var(--line);
            font-weight: 650;
            letter-spacing: -0.01em;
        }

        .brand-mark {
            width: 2rem;
            height: 2rem;
            border-radius: 0.6rem;
            background: var(--amber);
            color: #fff;
            display: grid;
            place-items: center;
            flex: none;
        }

        .nav {
            padding: 1rem 0.75rem 2rem;
            overflow: auto;
        }

        .nav-group {
            margin: 0 0.5rem 0.45rem;
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .nav-group + .nav-group { margin-top: 1.25rem; }

        .nav-item {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 0.7rem;
            margin-top: 0.15rem;
            padding: 0.55rem 0.75rem;
            border: 0;
            border-radius: 0.6rem;
            background: transparent;
            color: #4b5563;
            text-align: left;
            cursor: pointer;
        }

        .nav-item svg { width: 1.25rem; height: 1.25rem; flex: none; }

        .nav-item:hover { background: rgba(0, 0, 0, 0.05); }

        .nav-item[aria-current="page"] {
            background: #e5e7eb;
            color: var(--ink);
            font-weight: 600;
        }

        .main { flex: 1; min-width: 0; display: flex; flex-direction: column; }

        .topbar {
            height: var(--topbar);
            flex: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 0 1.5rem;
            background: var(--card);
            border-bottom: 1px solid var(--line);
        }

        .crumb { color: var(--muted); font-size: 13px; }
        .crumb strong { color: var(--ink); font-weight: 600; }

        .topbar-side { display: flex; align-items: center; gap: 0.75rem; }

        .badge {
            padding: 0.15rem 0.55rem;
            border-radius: 999px;
            background: #fef3c7;
            color: #92400e;
            font-size: 12px;
            font-weight: 600;
        }

        .user {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            color: #374151;
            font-weight: 600;
        }

        .avatar {
            width: 2rem;
            height: 2rem;
            border-radius: 999px;
            display: grid;
            place-items: center;
            background: #f3f4f6;
            color: #374151;
            font-size: 12px;
        }

        .content { flex: 1; overflow: auto; padding: 1.5rem 1.75rem 5.5rem; }

        .page-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.25rem;
        }

        .page-actions { display: flex; align-items: center; gap: 0.5rem; flex: none; }

        .role-switch {
            display: flex;
            padding: 0.15rem;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: #f3f4f6;
        }

        .role-switch button {
            border: 0;
            background: transparent;
            border-radius: 999px;
            padding: 0.28rem 0.75rem;
            font-weight: 650;
            cursor: pointer;
            color: #4b5563;
        }

        .role-switch button[aria-pressed="true"] {
            background: #fff;
            color: var(--ink);
            box-shadow: 0 1px 2px rgba(17, 24, 39, 0.08);
        }

        .nav-item[hidden] { display: none; }

        h1 { margin: 0; font-size: 1.5rem; font-weight: 700; letter-spacing: -0.02em; }

        .screen[hidden] { display: none; }

        .card {
            background: var(--card);
            border: 1px solid rgba(17, 24, 39, 0.08);
            border-radius: 0.85rem;
            box-shadow: 0 1px 2px rgba(17, 24, 39, 0.04);
        }

        .card + .card { margin-top: 1.25rem; }

        .card-head {
            padding: 1rem 1.25rem 0.85rem;
            border-bottom: 1px solid var(--line);
        }

        .card-head h2 { margin: 0; font-size: 0.95rem; font-weight: 650; }

        .card-head p, .subhead p, .hint {
            margin: 0.25rem 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        .card-body { padding: 1.15rem 1.25rem 1.25rem; }

        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem 1.25rem;
        }

        .span-2 { grid-column: 1 / -1; }

        .field { display: flex; flex-direction: column; gap: 0.35rem; min-width: 0; }

        .label {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
        }

        .req .label::after { content: " *"; color: var(--danger); }

        input[type="text"], input[type="password"], select, textarea {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 0.6rem;
            background: #fff;
            padding: 0.5rem 0.75rem;
            outline: none;
            box-shadow: 0 1px 1px rgba(17, 24, 39, 0.03);
        }

        textarea { min-height: 7.5rem; resize: vertical; line-height: 1.6; }

        input:focus, select:focus, textarea:focus {
            border-color: var(--amber);
            box-shadow: 0 0 0 1px var(--amber);
        }

        .affix { display: flex; align-items: stretch; }

        .affix input { border-radius: 0.6rem 0 0 0.6rem; }

        .suffix, .prefix {
            display: grid;
            place-items: center;
            padding: 0 0.75rem;
            border: 1px solid #d1d5db;
            background: #f9fafb;
            color: #4b5563;
            white-space: nowrap;
        }

        .suffix { border-left: 0; border-radius: 0 0.6rem 0.6rem 0; }
        .prefix { border-right: 0; border-radius: 0.6rem 0 0 0.6rem; }
        .affix .prefix + input { border-radius: 0 0.6rem 0.6rem 0; }

        .toggle {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            min-height: 2.35rem;
        }

        .toggle input { position: absolute; opacity: 0; width: 1px; height: 1px; }

        .switch {
            width: 2.35rem;
            height: 1.35rem;
            border-radius: 999px;
            background: #d1d5db;
            position: relative;
            flex: none;
            transition: background 0.15s ease;
        }

        .switch::after {
            content: "";
            position: absolute;
            top: 0.15rem;
            left: 0.15rem;
            width: 1.05rem;
            height: 1.05rem;
            border-radius: 999px;
            background: #fff;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.18);
            transition: transform 0.15s ease;
        }

        .toggle input:checked + .switch { background: var(--amber); }
        .toggle input:checked + .switch::after { transform: translateX(1rem); }
        .toggle input:focus-visible + .switch { outline: 2px solid var(--amber); outline-offset: 2px; }

        .steps {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.5rem;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--line);
        }

        .step {
            display: flex;
            align-items: flex-start;
            gap: 0.7rem;
            padding: 0.35rem;
            border: 0;
            background: transparent;
            text-align: left;
            cursor: pointer;
            border-radius: 0.6rem;
        }

        .step:hover { background: #f9fafb; }

        .step-no {
            width: 1.75rem;
            height: 1.75rem;
            border-radius: 999px;
            display: grid;
            place-items: center;
            flex: none;
            background: #f3f4f6;
            color: #6b7280;
            font-size: 12px;
            font-weight: 700;
        }

        .step[aria-current="step"] .step-no,
        .step.is-done .step-no { background: var(--amber); color: #fff; }

        .step-label { display: block; font-weight: 650; }
        .step-desc { display: block; color: var(--muted); font-size: 12px; margin-top: 0.1rem; }
        .step-pane[hidden] { display: none; }

        .wizard-foot {
            display: flex;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.9rem 1.25rem;
            border-top: 1px solid var(--line);
        }

        .btn {
            appearance: none;
            border-radius: 0.6rem;
            padding: 0.45rem 0.9rem;
            font-weight: 650;
            cursor: pointer;
            border: 1px solid transparent;
        }

        .btn-primary { background: var(--amber); color: #fff; }
        .btn-primary:hover { background: var(--amber-hover); }

        .btn-secondary {
            background: #fff;
            color: #374151;
            border-color: #d1d5db;
            box-shadow: 0 1px 1px rgba(17, 24, 39, 0.04);
        }

        .btn-secondary:hover { background: #f9fafb; }
        .btn[hidden] { display: none; }

        .subhead {
            margin: 0.35rem 0 0.15rem;
            padding-top: 0.35rem;
        }

        .subhead h3 { margin: 0; font-size: 0.95rem; }

        .inset {
            margin-top: 0.35rem;
            padding: 0.95rem 1rem 1.05rem;
            border: 1px solid var(--line);
            border-radius: 0.75rem;
            background: #fafafa;
        }

        .inset .subhead { margin: 0; padding: 0; }
        .inset .grid-2 { margin-top: 0.9rem; }

        .divider { height: 1px; background: var(--line); margin: 0.35rem 0; }

        .pay-grid {
            display: grid;
            grid-template-columns: minmax(0, 2fr) minmax(18rem, 1fr);
            gap: 1.25rem;
            align-items: start;
        }

        .stack { display: flex; flex-direction: column; gap: 1.25rem; }
        .stack .card { margin-top: 0; }

        .readonly {
            padding: 0.55rem 0.75rem;
            border-radius: 0.6rem;
            background: #f9fafb;
            border: 1px solid var(--line);
            color: #374151;
            overflow-x: auto;
            white-space: nowrap;
        }

        .editor textarea { min-height: 12rem; border-radius: 0 0 0.6rem 0.6rem; }
        .editor-bar {
            display: flex;
            gap: 0.25rem;
            padding: 0.35rem;
            border: 1px solid #d1d5db;
            border-bottom: 0;
            border-radius: 0.6rem 0.6rem 0 0;
            background: #f9fafb;
            color: #6b7280;
        }

        .editor-bar span {
            width: 1.75rem;
            height: 1.75rem;
            display: grid;
            place-items: center;
            border-radius: 0.35rem;
            font-size: 13px;
            font-weight: 700;
        }

        .toast {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 20;
            display: flex;
            align-items: flex-start;
            gap: 0.7rem;
            width: min(22rem, calc(100vw - 2rem));
            padding: 0.9rem 1rem;
            background: #fff;
            border: 1px solid rgba(17, 24, 39, 0.08);
            border-radius: 0.85rem;
            box-shadow: 0 12px 30px rgba(17, 24, 39, 0.12);
        }

        .toast[hidden] { display: none; }

        .toast-icon {
            width: 1.35rem;
            height: 1.35rem;
            border-radius: 999px;
            display: grid;
            place-items: center;
            background: #fef3c7;
            color: #b45309;
            flex: none;
            font-weight: 800;
        }

        .toast strong { display: block; }
        .toast p { margin: 0.15rem 0 0; color: var(--muted); font-size: 13px; }

        .screen {
            opacity: 1;
            transform: translateY(0);
            transition: opacity 0.45s ease, transform 0.45s ease;
        }

        .screen.is-leaving {
            opacity: 0;
            transform: translateY(10px);
            pointer-events: none;
        }

        .screen.is-entering {
            opacity: 0;
            transform: translateY(14px);
        }

        .demo-focus {
            box-shadow: 0 0 0 3px rgba(217, 119, 6, 0.28) !important;
            border-color: var(--amber) !important;
            transition: box-shadow 0.2s ease, border-color 0.2s ease;
        }

        .demo-pulse {
            animation: demo-pulse 0.7s ease;
        }

        @keyframes demo-pulse {
            0% { transform: scale(1); }
            40% { transform: scale(0.97); }
            100% { transform: scale(1); }
        }

        .demo-cursor {
            position: fixed;
            top: 0;
            left: 0;
            z-index: 40;
            width: 22px;
            height: 22px;
            pointer-events: none;
            opacity: 0;
            transform: translate(-10px, -4px);
            transition: opacity 0.2s ease;
            filter: drop-shadow(0 4px 8px rgba(17, 24, 39, 0.28));
        }

        .demo-cursor.is-visible { opacity: 1; }

        .demo-cursor.is-clicking .demo-cursor-ring {
            animation: demo-click 0.35s ease;
        }

        .demo-cursor svg {
            display: block;
            width: 22px;
            height: 22px;
        }

        .demo-cursor-ring {
            position: absolute;
            top: -6px;
            left: -6px;
            width: 22px;
            height: 22px;
            border-radius: 999px;
            border: 2px solid rgba(217, 119, 6, 0.55);
            opacity: 0;
            transform: scale(0.4);
        }

        @keyframes demo-click {
            0% { opacity: 0.85; transform: scale(0.45); }
            100% { opacity: 0; transform: scale(1.55); }
        }

        .demo-bar[hidden] { display: none; }

        .demo-bar {
            position: fixed;
            left: 50%;
            bottom: 1.25rem;
            z-index: 30;
            transform: translateX(-50%);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.55rem 0.7rem 0.55rem 0.9rem;
            background: rgba(17, 24, 39, 0.92);
            color: #f9fafb;
            border-radius: 999px;
            box-shadow: 0 14px 36px rgba(17, 24, 39, 0.28);
            backdrop-filter: blur(10px);
            max-width: calc(100vw - 2rem);
        }

        .demo-status {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            min-width: 0;
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
        }

        .demo-dot {
            width: 0.55rem;
            height: 0.55rem;
            border-radius: 999px;
            background: #fbbf24;
            box-shadow: 0 0 0 0 rgba(251, 191, 36, 0.55);
            animation: demo-live 1.4s ease infinite;
            flex: none;
        }

        .demo-dot.is-paused {
            background: #9ca3af;
            animation: none;
        }

        @keyframes demo-live {
            0% { box-shadow: 0 0 0 0 rgba(251, 191, 36, 0.5); }
            70% { box-shadow: 0 0 0 8px rgba(251, 191, 36, 0); }
            100% { box-shadow: 0 0 0 0 rgba(251, 191, 36, 0); }
        }

        .demo-caption {
            color: #d1d5db;
            font-weight: 500;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .demo-actions { display: flex; gap: 0.35rem; flex: none; }

        .demo-actions .btn {
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            font-size: 12px;
            border: 1px solid transparent;
        }

        .demo-actions .btn-secondary {
            background: rgba(255, 255, 255, 0.08);
            color: #f9fafb;
            border-color: rgba(255, 255, 255, 0.12);
            box-shadow: none;
        }

        .demo-actions .btn-secondary:hover { background: rgba(255, 255, 255, 0.14); }

        .demo-actions .btn-primary {
            background: var(--amber);
            color: #fff;
        }

        @media (max-width: 960px) {
            .shell { display: block; height: auto; min-height: 100vh; }
            .sidebar { width: auto; border-right: 0; border-bottom: 1px solid var(--line); }
            .nav { display: flex; gap: 0.75rem; overflow: auto; }
            .nav-group { display: none; }
            .content { overflow: visible; }
            .grid-2, .pay-grid, .steps { grid-template-columns: 1fr; }
            .span-2 { grid-column: auto; }
            .demo-bar {
                left: 1rem;
                right: 1rem;
                bottom: 1rem;
                transform: none;
                border-radius: 1rem;
                flex-wrap: wrap;
            }
            .demo-caption { white-space: normal; }
        }

        @media (prefers-reduced-motion: reduce) {
            .screen, .demo-cursor, .demo-dot { transition: none !important; animation: none !important; }
        }
    @endverbatim
    </style>
</head>
<body data-screens="{{ implode(' ', $allowedScreens) }}">
    <div class="shell">
        <aside class="sidebar">
            <div class="brand">
                <span class="brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M3 10.5 12 3l9 7.5"></path>
                        <path d="M6 9.5V21h12V9.5"></path>
                    </svg>
                </span>
                管理后台
            </div>
            <nav class="nav" aria-label="静态展示导航">
                @if (in_array('onboard', $allowedScreens, true) || in_array('merchant', $allowedScreens, true))
                    <div class="nav-group">平台管理</div>
                @endif
                @if (in_array('onboard', $allowedScreens, true))
                    <button class="nav-item" type="button" data-nav="onboard" data-demo="nav-onboard" @if ($allowedScreens[0] === 'onboard') aria-current="page" @endif>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 15c2-6 5-9 7-11 2 2 5 5 7 11-3 1-5 2-7 2s-4-1-7-2Z"></path><path d="M12 16v5"></path></svg>
                        商户入驻向导
                    </button>
                @endif
                @if (in_array('merchant', $allowedScreens, true))
                    <button class="nav-item" type="button" data-nav="merchant" data-demo="nav-merchant" @if ($allowedScreens[0] === 'merchant') aria-current="page" @endif>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 10h16v10H4z"></path><path d="M3 10 6 4h12l3 6"></path><path d="M10 20v-6h4v6"></path></svg>
                        商户配置
                    </button>
                @endif
                @if (in_array('payment', $allowedScreens, true))
                    <div class="nav-group">支付配置</div>
                    <button class="nav-item" type="button" data-nav="payment" data-demo="nav-payment" @if ($allowedScreens[0] === 'payment') aria-current="page" @endif>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="M3 10h18"></path></svg>
                        支付方式配置
                    </button>
                @endif
            </nav>
        </aside>

        <div class="main">
            <header class="topbar">
                <div class="crumb" id="crumb">{!! match ($allowedScreens[0]) {
                    'onboard' => '平台管理 / <strong>商户入驻向导</strong>',
                    'merchant' => '平台管理 / 商户 / <strong>编辑</strong>',
                    default => '支付配置 / 支付方式 / <strong>编辑</strong>',
                } !!}</div>
                <div class="topbar-side">
                    <span class="badge">静态展示</span>
                    <div class="user">
                        <span class="avatar" id="user-avatar">{{ $viewerAvatar }}</span>
                        <span id="user-name">{{ $viewerName }}</span>
                    </div>
                </div>
            </header>

            <main class="content">
                @if (in_array('onboard', $allowedScreens, true))
                <section class="screen" id="screen-onboard" data-screen="onboard" @unless ($allowedScreens[0] === 'onboard') hidden @endunless>

                    <div class="page-head">
                        <h1>商户入驻向导</h1>
                        <button class="btn btn-secondary" type="button" data-play="onboard">播放演示</button>
                    </div>
                    <form class="card" data-static-form>
                        <div class="steps" role="tablist">
                            <button class="step" type="button" data-step="0" role="tab" aria-current="step">
                                <span class="step-no">1</span>
                                <span>
                                    <span class="step-label">商户信息</span>
                                    <span class="step-desc">新商户的基本信息</span>
                                </span>
                            </button>
                            <button class="step" type="button" data-step="1" role="tab">
                                <span class="step-no">2</span>
                                <span>
                                    <span class="step-label">管理员账号</span>
                                    <span class="step-desc">该商户的第一个用户账号，创建后自动赋予“商户管理员”角色</span>
                                </span>
                            </button>
                            <button class="step" type="button" data-step="2" role="tab">
                                <span class="step-no">3</span>
                                <span>
                                    <span class="step-label">创建应用</span>
                                    <span class="step-desc">该商户用来接收订单的第一个应用</span>
                                </span>
                            </button>
                        </div>

                        <div class="card-body step-pane" data-pane="0">
                            <div class="grid-2">
                                <label class="field">
                                    <span class="label">所属商户级管理员</span>
                                    <select data-demo="onboard-manager">
                                        <option value="" selected></option>
                                        <option value="platform">平台直管</option>
                                        <option>admin_demo</option>
                                    </select>
                                    <span class="hint">不选表示由平台超级管理员直接管理，不属于任何商户级管理员名下。</span>
                                </label>
                                <label class="field">
                                    <span class="label">时区</span>
                                    <select data-demo="onboard-timezone">
                                        <option value="" selected></option>
                                        <option>UTC</option>
                                        <option>Asia/Shanghai</option>
                                        <option>Asia/Hong_Kong</option>
                                        <option>Asia/Tokyo</option>
                                        <option>America/Los_Angeles</option>
                                        <option>America/New_York</option>
                                        <option>Europe/London</option>
                                    </select>
                                    <span class="hint">决定该商户下用户在后台看到的时间所用时区，留空则使用系统默认时区</span>
                                </label>
                                <label class="field span-2">
                                    <span class="label">备注</span>
                                    <textarea rows="3" data-demo="onboard-note"></textarea>
                                </label>
                                <section class="span-2 inset">
                                    <div class="subhead">
                                        <h3>商户默认财务配置</h3>
                                        <p>新分配系统级支付方式时复制这些默认值；保存商户时，也会为既有分配中尚无独立配置的支付方式补齐配置。已有独立配置不会被覆盖，包括空值和 0。</p>
                                    </div>
                                    <div class="grid-2">
                                        <label class="field req">
                                            <span class="label">交易手续费比例</span>
                                            <span class="affix">
                                                <input type="text" value="" inputmode="decimal" data-demo="onboard-fee-percent">
                                                <span class="suffix">%</span>
                                            </span>
                                        </label>
                                        <label class="field req">
                                            <span class="label">交易固定手续费</span>
                                            <span class="affix">
                                                <span class="prefix">$</span>
                                                <input type="text" value="" inputmode="decimal" data-demo="onboard-fee-fixed">
                                            </span>
                                        </label>
                                        <label class="field req">
                                            <span class="label">退款手续费</span>
                                            <span class="affix">
                                                <span class="prefix">$</span>
                                                <input type="text" value="" inputmode="decimal" data-demo="onboard-refund-fee">
                                            </span>
                                        </label>
                                        <label class="field req">
                                            <span class="label">拒付手续费</span>
                                            <span class="affix">
                                                <span class="prefix">$</span>
                                                <input type="text" value="" inputmode="decimal" data-demo="onboard-chargeback-fee">
                                            </span>
                                        </label>
                                        <label class="field req">
                                            <span class="label">循环保证金比例</span>
                                            <span class="affix">
                                                <input type="text" value="" inputmode="decimal" data-demo="onboard-reserve-percent">
                                                <span class="suffix">%</span>
                                            </span>
                                        </label>
                                        <label class="field req">
                                            <span class="label">保证金锁定天数</span>
                                            <span class="affix">
                                                <input type="text" value="" inputmode="numeric" data-demo="onboard-reserve-days">
                                                <span class="suffix">天</span>
                                            </span>
                                        </label>
                                        <label class="field req">
                                            <span class="label">结算模式</span>
                                            <select data-demo="onboard-settle-mode">
                                                <option value="" selected></option>
                                                <option>立即结算</option>
                                                <option>上传物流单结算</option>
                                            </select>
                                            <span class="hint">立即结算从支付成功起计时；上传物流单结算从订单变为已发货起计时。仅对新订单生效。</span>
                                        </label>
                                        <label class="field req">
                                            <span class="label">结算周期</span>
                                            <span class="affix">
                                                <input type="text" value="" inputmode="numeric" data-demo="onboard-settle-days">
                                                <span class="suffix">天</span>
                                            </span>
                                            <span class="hint">商户所有支付方式统一使用此周期，0 天表示满足结算条件后立即结算。新订单保存配置快照，历史订单不变。</span>
                                        </label>
                                    </div>
                                </section>
                            </div>
                        </div>

                        <div class="card-body step-pane" data-pane="1" hidden>
                            <div class="grid-2">
                                <label class="field req">
                                    <span class="label">姓名</span>
                                    <input type="text" value="" data-demo="onboard-name">
                                </label>
                                <label class="field req">
                                    <span class="label">登录账号</span>
                                    <input type="text" value="" autocomplete="off" data-demo="onboard-account">
                                    <span class="hint">不需要真实邮箱，系统会自动用这个账号生成内部登录邮箱；如果本来就有可用邮箱，也可以直接填完整邮箱地址。</span>
                                </label>
                                <label class="field req span-2">
                                    <span class="label">密码</span>
                                    <input type="password" value="" placeholder="••••••••" autocomplete="new-password" data-demo="onboard-password">
                                </label>
                            </div>
                        </div>

                        <div class="card-body step-pane" data-pane="2" hidden>
                            <div class="grid-2">
                                <label class="field req">
                                    <span class="label">应用名称</span>
                                    <input type="text" value="" data-demo="onboard-app">
                                </label>
                                <label class="field req">
                                    <span class="label">官网域名</span>
                                    <input type="text" value="" placeholder="hat.com" data-demo="onboard-domain">
                                </label>
                                <div class="span-2">
                                    <label class="toggle">
                                        <input type="checkbox" data-demo="onboard-mail">
                                        <span class="switch" aria-hidden="true"></span>
                                        <span class="label">邮件通知</span>
                                    </label>
                                </div>
                                <label class="field span-2">
                                    <span class="label">付款链接邮件正文</span>
                                    <span class="editor">
                                        <span class="editor-bar" aria-hidden="true"><span>B</span><span>I</span><span>U</span></span>
                                        <textarea data-demo="onboard-mail-body"></textarea>
                                    </span>
                                    <span class="hint">留空则使用系统默认模板。支持变量 {customer_name}（客户姓名）、{payment_link}（付款链接），发送时会自动替换为实际内容；纯文本，换行会保留。同一商户下不同应用可以各自配置不同的正文。</span>
                                </label>
                            </div>
                        </div>

                        <div class="wizard-foot">
                            <button class="btn btn-secondary" type="button" id="wizard-prev" hidden>上一步</button>
                            <span></span>
                            <button class="btn btn-primary" type="button" id="wizard-next" data-demo="wizard-next">下一步</button>
                            <button class="btn btn-primary" type="button" id="wizard-submit" hidden data-demo="wizard-submit">创建商户</button>
                        </div>
                    </form>
                </section>

                                @endif
@if (in_array('merchant', $allowedScreens, true))
                <section class="screen" id="screen-merchant" data-screen="merchant" @unless ($allowedScreens[0] === 'merchant') hidden @endunless>

                    <div class="page-head">
                        <div>
                            <h1>编辑商户</h1>
                        </div>
                        <div class="page-actions">
                            <button class="btn btn-secondary" type="button" data-play="merchant">播放演示</button>
                            <button class="btn btn-primary" type="button" data-static-submit data-demo="merchant-save">保存</button>
                        </div>
                    </div>
                    <form data-static-form>
                        <section class="card">
                            <div class="card-head"><h2>基本信息</h2></div>
                            <div class="card-body grid-2">
                                <label class="field">
                                    <span class="label">所属商户级管理员</span>
                                    <select data-demo="merchant-manager">
                                        <option value="" selected></option>
                                        <option value="platform">平台直管</option>
                                        <option>admin_demo</option>
                                    </select>
                                    <span class="hint">不选表示由平台超级管理员直接管理，不属于任何商户级管理员名下。</span>
                                </label>
                                <label class="field req">
                                    <span class="label">商户名称</span>
                                    <input type="text" value="" data-demo="merchant-name">
                                </label>
                                <label class="field">
                                    <span class="label">时区</span>
                                    <select data-demo="merchant-timezone">
                                        <option value="" selected></option>
                                        <option>UTC</option>
                                        <option>Asia/Shanghai</option>
                                        <option>Asia/Hong_Kong</option>
                                        <option>Asia/Tokyo</option>
                                        <option>America/Los_Angeles</option>
                                        <option>America/New_York</option>
                                        <option>Europe/London</option>
                                    </select>
                                    <span class="hint">决定该商户下用户在后台看到的时间所用时区，留空则使用系统默认时区</span>
                                </label>
                                <div class="field">
                                    <span class="label">启用</span>
                                    <label class="toggle">
                                        <input type="checkbox" data-demo="merchant-enabled">
                                        <span class="switch" aria-hidden="true"></span>
                                        <span>已启用</span>
                                    </label>
                                </div>
                                <section class="span-2 inset">
                                    <div class="subhead">
                                        <h3>商户默认财务配置</h3>
                                        <p>新分配系统级支付方式时复制这些默认值；保存商户时，也会为既有分配中尚无独立配置的支付方式补齐配置。已有独立配置不会被覆盖，包括空值和 0。</p>
                                    </div>
                                    <div class="grid-2">
                                        <label class="field req">
                                            <span class="label">交易手续费比例</span>
                                            <span class="affix">
                                                <input type="text" value="" inputmode="decimal" data-demo="merchant-fee-percent">
                                                <span class="suffix">%</span>
                                            </span>
                                        </label>
                                        <label class="field req">
                                            <span class="label">交易固定手续费</span>
                                            <span class="affix">
                                                <span class="prefix">$</span>
                                                <input type="text" value="" inputmode="decimal" data-demo="merchant-fee-fixed">
                                            </span>
                                        </label>
                                        <label class="field req">
                                            <span class="label">退款手续费</span>
                                            <span class="affix">
                                                <span class="prefix">$</span>
                                                <input type="text" value="" inputmode="decimal" data-demo="merchant-refund-fee">
                                            </span>
                                        </label>
                                        <label class="field req">
                                            <span class="label">拒付手续费</span>
                                            <span class="affix">
                                                <span class="prefix">$</span>
                                                <input type="text" value="" inputmode="decimal" data-demo="merchant-chargeback-fee">
                                            </span>
                                        </label>
                                        <label class="field req">
                                            <span class="label">循环保证金比例</span>
                                            <span class="affix">
                                                <input type="text" value="" inputmode="decimal" data-demo="merchant-reserve-percent">
                                                <span class="suffix">%</span>
                                            </span>
                                        </label>
                                        <label class="field req">
                                            <span class="label">保证金锁定天数</span>
                                            <span class="affix">
                                                <input type="text" value="" inputmode="numeric" data-demo="merchant-reserve-days">
                                                <span class="suffix">天</span>
                                            </span>
                                        </label>
                                        <label class="field req">
                                            <span class="label">结算模式</span>
                                            <select data-demo="merchant-settle-mode">
                                                <option value="" selected></option>
                                                <option>立即结算</option>
                                                <option>上传物流单结算</option>
                                            </select>
                                            <span class="hint">立即结算从支付成功起计时；上传物流单结算从订单变为已发货起计时。仅对新订单生效。</span>
                                        </label>
                                        <label class="field req">
                                            <span class="label">结算周期</span>
                                            <span class="affix">
                                                <input type="text" value="" inputmode="numeric" data-demo="merchant-settle-days">
                                                <span class="suffix">天</span>
                                            </span>
                                            <span class="hint">商户所有支付方式统一使用此周期，0 天表示满足结算条件后立即结算。新订单保存配置快照，历史订单不变。</span>
                                        </label>
                                    </div>
                                </section>
                                <label class="field span-2">
                                    <span class="label">备注</span>
                                    <textarea rows="3" data-demo="merchant-remark"></textarea>
                                </label>
                            </div>
                        </section>
                    </form>
                </section>

                                @endif
@if (in_array('payment', $allowedScreens, true))
                <section class="screen" id="screen-payment" data-screen="payment" @unless ($allowedScreens[0] === 'payment') hidden @endunless>

                    <div class="page-head">
                        <div>
                            <h1>编辑支付方式</h1>
                        </div>
                        <div class="page-actions">
                            <button class="btn btn-secondary" type="button" data-play="payment">播放演示</button>
                            <button class="btn btn-primary" type="button" data-static-submit data-demo="payment-save">保存</button>
                        </div>
                    </div>
                    <form data-static-form>
                        <div class="pay-grid">
                            <section class="card">
                                <div class="card-head"><h2>基本信息</h2></div>
                                <div class="card-body grid-2">
                                    <label class="field span-2">
                                        <span class="label">所属商户</span>
                                        <select data-demo="payment-merchant">
                                            <option value="" selected></option>
                                            <option value="system">留空为系统级</option>
                                            <option>北境户外</option>
                                        </select>
                                        <span class="hint">留空表示创建系统级支付方式（挂在自己名下），可在下方分配给多个商户使用；商户只能使用，不能修改配置</span>
                                    </label>
                                    <label class="field req">
                                        <span class="label">支付方式代码</span>
                                        <input type="text" value="" placeholder="paypal / stripe" data-demo="payment-code">
                                    </label>
                                    <label class="field req">
                                        <span class="label">展示名称</span>
                                        <input type="text" value="" data-demo="payment-name">
                                    </label>
                                    <label class="toggle">
                                        <input type="checkbox" data-demo="pay-active">
                                        <span class="switch" aria-hidden="true"></span>
                                        <span class="label">启用</span>
                                    </label>
                                    <label class="toggle">
                                        <input type="checkbox" data-demo="pay-logistics">
                                        <span class="switch" aria-hidden="true"></span>
                                        <span class="label">同步物流信息</span>
                                    </label>
                                    <div class="field span-2">
                                        <label class="toggle">
                                            <input type="checkbox" data-demo="pay-return">
                                            <span class="switch" aria-hidden="true"></span>
                                            <span class="label">允许返回源站</span>
                                        </label>
                                        <span class="hint">支付完成后是否允许返回源站；订单平台为 invoice 时强制不允许，优先级高于此配置</span>
                                    </div>

                                    <div class="span-2 divider"></div>
                                    <div class="span-2 subhead">
                                        <h3>电商网站配置</h3>
                                    </div>
                                    <label class="field req span-2">
                                        <span class="label">网站域名</span>
                                        <input id="pay-domain" type="text" value="" placeholder="https://example.com" data-demo="pay-domain">
                                        <span class="hint">WordPress 网站域名，必须以 https:// 开头，如 https://example.com</span>
                                    </label>
                                    <label class="field req">
                                        <span class="label">Rest Api Key</span>
                                        <input type="text" value="" placeholder="ck_xxxxxxxx" autocomplete="off" data-demo="pay-api-key">
                                    </label>
                                    <label class="field req">
                                        <span class="label">Rest Api Secret</span>
                                        <input type="text" value="" placeholder="cs_xxxxxxxx" autocomplete="off" data-demo="pay-api-secret">
                                    </label>
                                    <label class="field req span-2">
                                        <span class="label">商品匹配模式</span>
                                        <select data-demo="pay-match">
                                            <option value="" selected></option>
                                            <option>匹配</option>
                                            <option>创建</option>
                                            <option>虚拟</option>
                                            <option>复制</option>
                                        </select>
                                    </label>
                                    <label class="field">
                                        <span class="label">Invoice 前缀</span>
                                        <input type="text" value="" data-demo="pay-invoice-prefix">
                                        <span class="hint">生成 invoice 商品时使用的名称前缀，留空表示不加前缀</span>
                                    </label>
                                    <label class="field">
                                        <span class="label">虚拟商品前缀</span>
                                        <input type="text" value="" data-demo="pay-virtual-prefix">
                                        <span class="hint">生成虚拟商品时使用的名称前缀，留空表示不加前缀</span>
                                    </label>
                                    <label class="field">
                                        <span class="label">系统订单号前缀</span>
                                        <input type="text" value="" data-demo="pay-order-prefix">
                                    </label>
                                    <label class="field">
                                        <span class="label">系统订单号格式</span>
                                        <select id="order-no-format" data-demo="pay-order-format">
                                            <option value="" selected></option>
                                            <option value="numeric">纯数字</option>
                                            <option value="alnum">大写字母+数字组合</option>
                                        </select>
                                        <span class="hint">留空则使用系统默认订单号格式；选择后，系统订单号 = 上方前缀 + 指定格式与长度的随机字符</span>
                                    </label>
                                    <label class="field">
                                        <span class="label">系统订单号随机部分长度</span>
                                        <input type="text" value="" inputmode="numeric" data-demo="pay-order-length">
                                        <span class="hint">系统订单号总长度（含前缀），15-30 位之间</span>
                                    </label>
                                </div>
                            </section>

                            <div class="stack">
                                <section class="card">
                                    <div class="card-head"><h2>网关配置</h2></div>
                                    <div class="card-body grid-2">
                                        <label class="field span-2">
                                            <span class="label">配置模板</span>
                                            <select data-demo="pay-template">
                                                <option value="" selected></option>
                                                <option>Stripe</option>
                                                <option>PayPal</option>
                                            </select>
                                            <span class="hint">选择支付类型模板，下方出现对应配置项。加密渠道选 payment_config_tag 为 crypto / crypto_* 的模板（如 TRON USDT），本站听链不入 WP</span>
                                        </label>
                                        <div class="field span-2">
                                            <span class="label">Webhook 地址</span>
                                            <div class="readonly" id="webhook-url" data-demo="pay-webhook"></div>
                                        </div>
                                        <label class="field">
                                            <span class="label">Client ID</span>
                                            <input type="text" value="" autocomplete="off" data-demo="pay-client-id">
                                        </label>
                                        <label class="field">
                                            <span class="label">Client Secret</span>
                                            <input type="text" value="" autocomplete="off" data-demo="pay-client-secret">
                                        </label>
                                        <label class="field">
                                            <span class="label">Webhook ID</span>
                                            <input type="text" value="" autocomplete="off" data-demo="pay-webhook-id">
                                        </label>
                                        <label class="field">
                                            <span class="label">环境</span>
                                            <select data-demo="pay-env">
                                                <option value="" selected></option>
                                                <option>sandbox</option>
                                                <option>live</option>
                                            </select>
                                            <span class="hint">只能填 live 或 sandbox。系统注册时字段名是 env，插件也同时接受 mode。</span>
                                        </label>
                                    </div>
                                </section>

                                <section class="card">
                                    <div class="card-head">
                                        <h2>风控阈值（USD）</h2>
                                        <p>金额和笔数限制填 0 表示不限制；连续建单失败阈值留空则沿用系统默认</p>
                                    </div>
                                    <div class="card-body grid-2">
                                        <label class="field">
                                            <span class="label">单月交易总金额上限</span>
                                            <span class="affix"><span class="prefix">$</span><input type="text" value="" inputmode="decimal" data-demo="pay-max-month"></span>
                                        </label>
                                        <label class="field">
                                            <span class="label">单笔交易金额上限</span>
                                            <span class="affix"><span class="prefix">$</span><input type="text" value="" inputmode="decimal" data-demo="pay-max-txn"></span>
                                        </label>
                                        <label class="field">
                                            <span class="label">单日交易总金额上限</span>
                                            <span class="affix"><span class="prefix">$</span><input type="text" value="" inputmode="decimal" data-demo="pay-max-day"></span>
                                        </label>
                                        <label class="field">
                                            <span class="label">单日交易笔数上限</span>
                                            <input type="text" value="" inputmode="numeric" data-demo="pay-max-count">
                                        </label>
                                        <label class="field span-2">
                                            <span class="label">连续建单失败停用阈值</span>
                                            <input type="text" value="" placeholder="沿用系统默认（5 次）" inputmode="numeric" data-demo="pay-max-failed">
                                            <span class="hint">填写大于或等于 1 的整数，留空沿用系统默认。连续建单失败达到阈值时自动禁用通道，成功建单后重置计数。</span>
                                        </label>
                                    </div>
                                </section>

                                <section class="card">
                                    <div class="card-head">
                                        <h2>手续费（USD）</h2>
                                        <p>退款/拒付时按此固定金额（USD）收取手续费，从商户余额扣除；填 0 表示不收取</p>
                                    </div>
                                    <div class="card-body grid-2">
                                        <label class="field">
                                            <span class="label">退款手续费</span>
                                            <span class="affix"><span class="prefix">$</span><input type="text" value="" inputmode="decimal" data-demo="pay-refund-fee"></span>
                                        </label>
                                        <label class="field">
                                            <span class="label">拒付手续费</span>
                                            <span class="affix"><span class="prefix">$</span><input type="text" value="" inputmode="decimal" data-demo="pay-chargeback-fee"></span>
                                        </label>
                                        <label class="field">
                                            <span class="label">交易百分比手续费</span>
                                            <span class="affix"><input id="fee-percent" type="text" value="" inputmode="decimal" data-demo="fee-percent"><span class="suffix">%</span></span>
                                            <span class="hint">每笔交易支付成功入账时，按百分比+固定手续费从订单金额里扣除，商户实际到账金额 = 订单折算 USD 金额 - 百分比手续费 - 固定手续费。下单时会按订单金额校验是否满足最小交易金额，不满足则直接拒单。</span>
                                        </label>
                                        <label class="field">
                                            <span class="label">交易固定手续费</span>
                                            <span class="affix"><span class="prefix">$</span><input id="fee-fixed" type="text" value="" inputmode="decimal" data-demo="fee-fixed"></span>
                                        </label>
                                        <label class="field">
                                            <span class="label">循环保证金比例</span>
                                            <span class="affix"><input type="text" value="" inputmode="decimal" data-demo="pay-reserve-percent"><span class="suffix">%</span></span>
                                            <span class="hint">按净结算金额划分循环保证金，锁定期从该订单结算时起算。</span>
                                        </label>
                                        <label class="field">
                                            <span class="label">保证金锁定天数</span>
                                            <span class="affix"><input type="text" value="" inputmode="numeric" data-demo="pay-reserve-days"><span class="suffix">天</span></span>
                                        </label>
                                        <div class="field span-2">
                                            <span class="label">最小交易金额</span>
                                            <div class="readonly" id="min-amount" data-demo="pay-min-amount"></div>
                                        </div>
                                    </div>
                                </section>
                            </div>

                        </div>
                    </form>
                </section>
                @endif
            </main>
        </div>
    </div>

    <div class="toast" id="toast" hidden role="status">
        <span class="toast-icon">!</span>
        <div>
            <strong>此为静态展示，不会提交</strong>
            <p>页面没有连接数据库，也不会创建商户、账号或支付方式。</p>
        </div>
    </div>

    <div class="demo-cursor" id="demo-cursor" aria-hidden="true">
        <span class="demo-cursor-ring"></span>
        <svg viewBox="0 0 24 24" fill="none">
            <path d="M5.5 3.8 18.2 12.4l-5.4 1.1 2.4 6.4-2.4.9-2.5-6.5-4.3 3.7Z" fill="#111827" stroke="#fff" stroke-width="1.2" stroke-linejoin="round"></path>
        </svg>
    </div>

    <div class="demo-bar" id="demo-bar" hidden role="group" aria-label="自动演示控制">
        <div class="demo-status">
            <span class="demo-dot" id="demo-dot"></span>
            <span id="demo-state">自动演示中</span>
            <span class="demo-caption" id="demo-caption">准备开始</span>
        </div>
        <div class="demo-actions">
            <button class="btn btn-secondary" type="button" id="demo-toggle">暂停</button>
            <button class="btn btn-primary" type="button" id="demo-restart">重播</button>
        </div>
    </div>

    <script>
        const screens = {
            onboard: { crumb: "平台管理 / <strong>商户入驻向导</strong>", title: "商户入驻向导 · 静态展示" },
            merchant: { crumb: "平台管理 / 商户 / <strong>编辑</strong>", title: "编辑商户 · 静态展示" },
            payment: { crumb: "支付配置 / 支付方式 / <strong>编辑</strong>", title: "编辑支付方式 · 静态展示" },
        };

        const crumb = document.getElementById("crumb");
        const demoCursor = document.getElementById("demo-cursor");
        const demoBar = document.getElementById("demo-bar");
        const demoDot = document.getElementById("demo-dot");
        const demoState = document.getElementById("demo-state");
        const demoCaption = document.getElementById("demo-caption");
        const demoToggle = document.getElementById("demo-toggle");
        const demoRestart = document.getElementById("demo-restart");
        const allowedScreens = @json($allowedScreens);
        let toastTimer = 0;
        let currentScreen = allowedScreens[0];
        let screenTransition = Promise.resolve();
        const player = {
            running: false,
            paused: false,
            synthetic: false,
            token: 0,
            screen: "",
            waitResolve: null,
        };

        function canSee(id) {
            return allowedScreens.indexOf(id) !== -1;
        }

        function demo(name) {
            return document.querySelector('[data-demo="' + name + '"]');
        }

        function showToast() {
            const toast = document.getElementById("toast");
            toast.hidden = false;
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => { toast.hidden = true; }, 2600);
        }

        function updateNav(id) {
            document.querySelectorAll("[data-nav]").forEach((item) => {
                if (item.dataset.nav === id) {
                    item.setAttribute("aria-current", "page");
                } else {
                    item.removeAttribute("aria-current");
                }
            });
            crumb.innerHTML = screens[id].crumb;
            document.title = screens[id].title;
            const nextUrl = "#" + id;
            if (location.hash !== nextUrl || location.search !== "") {
                history.replaceState(null, "", location.pathname + nextUrl);
            }
        }

        function showScreen(id, animated = false) {
            if (!screens[id] || !canSee(id)) {
                id = allowedScreens[0];
            }

            if (!animated || id === currentScreen) {
                document.querySelectorAll("[data-screen]").forEach((screen) => {
                    screen.hidden = screen.dataset.screen !== id;
                    screen.classList.remove("is-leaving", "is-entering");
                });
                currentScreen = id;
                updateNav(id);
                return Promise.resolve();
            }

            const from = document.querySelector('[data-screen="' + currentScreen + '"]');
            const to = document.querySelector('[data-screen="' + id + '"]');
            currentScreen = id;
            updateNav(id);

            screenTransition = new Promise((resolve) => {
                if (from) {
                    from.classList.add("is-leaving");
                }

                window.setTimeout(() => {
                    if (from) {
                        from.hidden = true;
                        from.classList.remove("is-leaving");
                    }
                    to.hidden = false;
                    to.classList.add("is-entering");
                    requestAnimationFrame(() => {
                        requestAnimationFrame(() => {
                            to.classList.remove("is-entering");
                        });
                    });
                    window.setTimeout(resolve, 450);
                }, 280);
            });

            return screenTransition;
        }

        document.querySelectorAll("[data-nav]").forEach((item) => {
            item.addEventListener("click", () => {
                if (player.synthetic) {
                    showScreen(item.dataset.nav, true);
                    return;
                }
                if (!canSee(item.dataset.nav)) {
                    return;
                }
                if (player.running) {
                    stopDemo();
                }
                showScreen(item.dataset.nav);
            });
        });

        const panes = [...document.querySelectorAll("[data-pane]")];
        const stepButtons = [...document.querySelectorAll("[data-step]")];
        const prevButton = document.getElementById("wizard-prev");
        const nextButton = document.getElementById("wizard-next");
        const submitButton = document.getElementById("wizard-submit");

        function showStep(index) {
            panes.forEach((pane) => {
                pane.hidden = Number(pane.dataset.pane) !== index;
            });
            stepButtons.forEach((button) => {
                const current = Number(button.dataset.step);
                button.classList.toggle("is-done", current < index);
                if (current === index) {
                    button.setAttribute("aria-current", "step");
                } else {
                    button.removeAttribute("aria-current");
                }
                button.querySelector(".step-no").textContent = current < index ? "✓" : String(current + 1);
            });
            prevButton.hidden = index === 0;
            nextButton.hidden = index === panes.length - 1;
            submitButton.hidden = index !== panes.length - 1;
        }

        stepButtons.forEach((button) => {
            button.addEventListener("click", () => {
                if (player.running && !player.paused && !player.synthetic) {
                    return;
                }
                showStep(Number(button.dataset.step));
            });
        });
        if (prevButton && nextButton && submitButton) {
            prevButton.addEventListener("click", () => {
                if (player.running && !player.paused && !player.synthetic) {
                    return;
                }
                const current = panes.findIndex((pane) => !pane.hidden);
                showStep(Math.max(0, current - 1));
            });
            nextButton.addEventListener("click", () => {
                if (player.running && !player.paused && !player.synthetic) {
                    return;
                }
                const current = panes.findIndex((pane) => !pane.hidden);
                showStep(Math.min(panes.length - 1, current + 1));
            });
            submitButton.addEventListener("click", showToast);
        }

        document.querySelectorAll("[data-static-form]").forEach((form) => {
            form.addEventListener("submit", (event) => {
                event.preventDefault();
                showToast();
            });
        });
        document.querySelectorAll("[data-static-submit]").forEach((button) => {
            button.addEventListener("click", showToast);
        });

        const domainInput = document.getElementById("pay-domain");
        const webhook = document.getElementById("webhook-url");
        function syncWebhook() {
            const domain = domainInput.value.trim().replace(/\/+$/, "");
            webhook.textContent = domain ? domain + "/wp-json/payment-plugin/v1/webhook/paypal" : "";
        }

        if (domainInput && webhook) {
            domainInput.addEventListener("input", syncWebhook);
            domainInput.addEventListener("change", syncWebhook);
        }

        const feePercent = document.getElementById("fee-percent");
        const feeFixed = document.getElementById("fee-fixed");
        const minAmount = document.getElementById("min-amount");

        function updateMinAmount() {
            const percentRaw = feePercent.value.trim();
            const fixedRaw = feeFixed.value.trim();
            if (percentRaw === "" && fixedRaw === "") {
                minAmount.textContent = "";
                return;
            }

            const percent = Number.parseFloat(percentRaw) || 0;
            const fixed = Number.parseFloat(fixedRaw) || 0;

            if (fixed <= 0) {
                minAmount.textContent = "固定手续费为 0，无最小交易金额限制";
                return;
            }

            const remaining = 1 - percent / 100;
            if (remaining <= 0) {
                minAmount.textContent = "百分比手续费 ≥ 100%，任何金额都无法覆盖手续费，请调整费率";
                return;
            }

            minAmount.textContent = "当前费率组合下，最小交易金额约为 $" + (fixed / remaining).toFixed(2) + "（USD），低于此金额的订单会被拒绝";
        }

        if (feePercent && feeFixed && minAmount) {
            feePercent.addEventListener("change", updateMinAmount);
            feeFixed.addEventListener("change", updateMinAmount);
            feePercent.addEventListener("input", updateMinAmount);
            feeFixed.addEventListener("input", updateMinAmount);
        }

        function resetDemoFields() {
            document.querySelectorAll("form input, form textarea, form select").forEach((el) => {
                if (el.type === "checkbox" || el.type === "radio") {
                    el.checked = false;
                } else if (el.tagName === "SELECT") {
                    el.selectedIndex = 0;
                } else {
                    el.value = "";
                }
            });
            if (webhook) {
                webhook.textContent = "";
            }
            if (minAmount) {
                minAmount.textContent = "";
            }
        }

        function setCaption(text) {
            demoCaption.textContent = text;
        }

        function sleep(ms) {
            return new Promise((resolve) => {
                const start = performance.now();
                const tick = () => {
                    if (!player.running) {
                        resolve(false);
                        return;
                    }
                    if (player.paused) {
                        player.waitResolve = () => {
                            player.waitResolve = null;
                            window.requestAnimationFrame(tick);
                        };
                        return;
                    }
                    if (performance.now() - start >= ms) {
                        resolve(true);
                        return;
                    }
                    window.requestAnimationFrame(tick);
                };
                window.requestAnimationFrame(tick);
            });
        }

        function moveCursorTo(el) {
            const rect = el.getBoundingClientRect();
            const x = rect.left + Math.min(rect.width * 0.62, Math.max(18, rect.width - 12));
            const y = rect.top + Math.min(rect.height * 0.55, Math.max(14, rect.height / 2));
            demoCursor.style.transform = "translate(" + (x - 4) + "px, " + (y - 2) + "px)";
            demoCursor.classList.add("is-visible");
        }

        async function focusEl(el, ms = 420) {
            el.classList.add("demo-focus");
            moveCursorTo(el);
            const ok = await sleep(ms);
            el.classList.remove("demo-focus");
            return ok;
        }

        async function clickEl(el) {
            if (!(await focusEl(el, 380))) {
                return false;
            }
            demoCursor.classList.add("is-clicking");
            el.classList.add("demo-pulse");
            await sleep(180);
            player.synthetic = true;
            try {
                el.click();
            } finally {
                player.synthetic = false;
            }
            await sleep(220);
            demoCursor.classList.remove("is-clicking");
            el.classList.remove("demo-pulse");
            return player.running;
        }

        async function typeInto(el, text, clear = true) {
            if (!(await focusEl(el, 320))) {
                return false;
            }
            el.focus();
            if (clear) {
                el.value = "";
                el.dispatchEvent(new Event("input", { bubbles: true }));
            }
            for (const char of text) {
                if (!player.running) {
                    return false;
                }
                while (player.paused) {
                    await sleep(80);
                    if (!player.running) {
                        return false;
                    }
                }
                el.value += char;
                el.dispatchEvent(new Event("input", { bubbles: true }));
                if (!(await sleep(28 + Math.random() * 42))) {
                    return false;
                }
            }
            el.dispatchEvent(new Event("change", { bubbles: true }));
            return true;
        }

        async function selectValue(el, value) {
            if (!(await focusEl(el, 320))) {
                return false;
            }
            el.value = value;
            el.dispatchEvent(new Event("change", { bubbles: true }));
            return sleep(360);
        }

        async function toggleCheckbox(el, checked) {
            if (!(await focusEl(el.closest(".toggle") || el, 320))) {
                return false;
            }
            if (el.checked !== checked) {
                el.click();
            }
            return sleep(320);
        }

        async function ensureVisible(el) {
            el.scrollIntoView({ behavior: "smooth", block: "center", inline: "nearest" });
            return sleep(420);
        }

        async function visitFields(steps) {
            for (const step of steps) {
                const el = demo(step.name);
                if (!el) {
                    return false;
                }
                setCaption(step.caption);
                if (!(await ensureVisible(el))) {
                    return false;
                }
                let ok = false;
                if (step.type !== undefined) {
                    ok = await typeInto(el, step.type);
                } else if (step.select !== undefined) {
                    ok = await selectValue(el, step.select);
                } else if (step.toggle !== undefined) {
                    ok = await toggleCheckbox(el, step.toggle);
                } else {
                    ok = await focusEl(el, step.focus || 650);
                }
                if (!ok) {
                    return false;
                }
            }
            return true;
        }

        async function playOnboard() {
            showStep(0);
            if (!(await visitFields([
                { name: "onboard-manager", caption: "选择所属商户级管理员", select: "admin_demo" },
                { name: "onboard-timezone", caption: "选择时区", select: "Asia/Shanghai" },
                { name: "onboard-note", caption: "填写备注", type: "北境户外，主营冲锋衣与露营装备。" },
                { name: "onboard-fee-percent", caption: "填写交易手续费比例", type: "10" },
                { name: "onboard-fee-fixed", caption: "填写交易固定手续费", type: "0" },
                { name: "onboard-refund-fee", caption: "填写退款手续费", type: "0" },
                { name: "onboard-chargeback-fee", caption: "填写拒付手续费", type: "0" },
                { name: "onboard-reserve-percent", caption: "填写循环保证金比例", type: "10" },
                { name: "onboard-reserve-days", caption: "填写保证金锁定天数", type: "30" },
                { name: "onboard-settle-mode", caption: "选择结算模式", select: "上传物流单结算" },
                { name: "onboard-settle-days", caption: "填写结算周期", type: "7" },
            ]))) return false;
            if (!(await clickEl(demo("wizard-next")))) return false;
            if (!(await visitFields([
                { name: "onboard-name", caption: "填写管理员姓名", type: "张三" },
                { name: "onboard-account", caption: "填写登录账号", type: "zhangsan" },
                { name: "onboard-password", caption: "填写密码", type: "88888888" },
            ]))) return false;
            if (!(await clickEl(demo("wizard-next")))) return false;
            if (!(await visitFields([
                { name: "onboard-app", caption: "填写应用名称", type: "app_demo" },
                { name: "onboard-domain", caption: "填写官网域名", type: "www.example.com" },
                { name: "onboard-mail", caption: "邮件通知保持关闭", toggle: false },
            ]))) return false;
            return clickEl(demo("wizard-submit"));
        }

        async function playMerchant() {
            if (!(await visitFields([
                { name: "merchant-manager", caption: "选择所属商户级管理员", select: "admin_demo" },
                { name: "merchant-name", caption: "填写商户名称", type: "北境户外" },
                { name: "merchant-timezone", caption: "选择时区", select: "Asia/Shanghai" },
                { name: "merchant-enabled", caption: "启用商户", toggle: true },
                { name: "merchant-fee-percent", caption: "填写交易手续费比例", type: "10" },
                { name: "merchant-fee-fixed", caption: "填写交易固定手续费", type: "0" },
                { name: "merchant-refund-fee", caption: "填写退款手续费", type: "0" },
                { name: "merchant-chargeback-fee", caption: "填写拒付手续费", type: "0" },
                { name: "merchant-reserve-percent", caption: "填写循环保证金比例", type: "10" },
                { name: "merchant-reserve-days", caption: "填写保证金锁定天数", type: "30" },
                { name: "merchant-settle-mode", caption: "选择结算模式", select: "上传物流单结算" },
                { name: "merchant-settle-days", caption: "填写结算周期", type: "7" },
                { name: "merchant-remark", caption: "填写备注", type: "主营冲锋衣、帐篷与露营装备。" },
            ]))) {
                return false;
            }
            setCaption("保存商户配置");
            return clickEl(demo("merchant-save"));
        }

        async function playPayment() {
            if (!(await visitFields([
                { name: "payment-merchant", caption: "选择所属商户", select: "北境户外" },
                { name: "payment-code", caption: "填写支付方式代码", type: "paypal" },
                { name: "payment-name", caption: "填写展示名称", type: "PayPal" },
                { name: "pay-active", caption: "确认启用", toggle: true },
                { name: "pay-logistics", caption: "确认同步物流信息", toggle: true },
                { name: "pay-return", caption: "确认允许返回源站", toggle: true },
                { name: "pay-domain", caption: "填写网站域名", type: "https://www.example.com" },
                { name: "pay-api-key", caption: "填写 Rest Api Key", type: "ck_demo" },
                { name: "pay-api-secret", caption: "填写 Rest Api Secret", type: "cs_demo" },
                { name: "pay-match", caption: "选择商品匹配模式", select: "匹配" },
                { name: "pay-invoice-prefix", caption: "填写 Invoice 前缀", type: "INV" },
                { name: "pay-virtual-prefix", caption: "填写虚拟商品前缀", type: "VP" },
                { name: "pay-order-prefix", caption: "填写系统订单号前缀", type: "ORD" },
                { name: "pay-order-format", caption: "选择系统订单号格式", select: "alnum" },
                { name: "pay-order-length", caption: "填写订单号长度", type: "15" },
                { name: "pay-template", caption: "选择 PayPal 配置模板", select: "PayPal" },
                { name: "pay-webhook", caption: "查看 PayPal Webhook 地址", focus: 800 },
                { name: "pay-client-id", caption: "填写 PayPal Client ID", type: "AX-demo" },
                { name: "pay-client-secret", caption: "填写 PayPal Client Secret", type: "ED-demo" },
                { name: "pay-webhook-id", caption: "填写 PayPal Webhook ID", type: "WH-demo" },
                { name: "pay-env", caption: "选择 PayPal 环境", select: "sandbox" },
                { name: "pay-max-month", caption: "填写单月交易上限", type: "20000" },
                { name: "pay-max-txn", caption: "填写单笔交易上限", type: "500" },
                { name: "pay-max-day", caption: "填写单日交易上限", type: "2000" },
                { name: "pay-max-count", caption: "填写单日交易笔数上限", type: "50" },
                { name: "pay-max-failed", caption: "填写连续建单失败阈值", type: "5" },
                { name: "pay-refund-fee", caption: "填写退款手续费", type: "0" },
                { name: "pay-chargeback-fee", caption: "填写拒付手续费", type: "15" },
                { name: "fee-percent", caption: "填写交易百分比手续费", type: "10" },
                { name: "fee-fixed", caption: "填写交易固定手续费", type: "0" },
                { name: "pay-reserve-percent", caption: "填写循环保证金比例", type: "10" },
                { name: "pay-reserve-days", caption: "填写保证金锁定天数", type: "30" },
                { name: "pay-min-amount", caption: "查看最小交易金额", focus: 900 },
            ]))) {
                return false;
            }
            setCaption("保存支付方式");
            return clickEl(demo("payment-save"));
        }

        const screenDemos = {
            onboard: playOnboard,
            merchant: playMerchant,
            payment: playPayment,
        };

        async function runScreen(id, token) {
            while (player.running && player.token === token && player.screen === id) {
                resetDemoFields();
                if (id === "onboard") {
                    showStep(0);
                }
                if (!(await screenDemos[id]())) {
                    break;
                }
                setCaption("本页演示结束，即将重播");
                if (!(await sleep(1200))) {
                    break;
                }
            }
            if (player.token === token) {
                stopDemo();
            }
        }

        function setPlayingUi(playing) {
            demoDot.classList.toggle("is-paused", !playing);
            demoState.textContent = playing ? "演示中" : "已暂停";
            demoToggle.textContent = playing ? "暂停" : "继续";
            document.querySelectorAll("[data-play]").forEach((button) => {
                const active = player.running && player.screen === button.dataset.play;
                if (!active) {
                    button.textContent = "播放演示";
                } else {
                    button.textContent = player.paused ? "继续演示" : "暂停演示";
                }
            });
        }

        function stopDemo() {
            player.running = false;
            player.paused = false;
            player.screen = "";
            player.token += 1;
            if (player.waitResolve) {
                player.waitResolve();
                player.waitResolve = null;
            }
            demoCursor.classList.remove("is-visible", "is-clicking");
            demoBar.hidden = true;
            setPlayingUi(false);
        }

        function startScreenDemo(id) {
            if (!canSee(id) || !screenDemos[id]) {
                return;
            }
            player.token += 1;
            player.running = true;
            player.paused = false;
            player.screen = id;
            demoBar.hidden = false;
            setPlayingUi(true);
            setCaption("开始演示");
            showScreen(id);
            runScreen(id, player.token);
        }

        function pauseDemo() {
            player.paused = true;
            setPlayingUi(false);
            setCaption("演示已暂停");
        }

        function resumeDemo() {
            player.paused = false;
            setPlayingUi(true);
            if (player.waitResolve) {
                player.waitResolve();
            }
        }

        demoToggle.addEventListener("click", () => {
            if (!player.running || player.paused) {
                resumeDemo();
            } else {
                pauseDemo();
            }
        });

        demoRestart.addEventListener("click", () => {
            const id = player.screen || currentScreen;
            stopDemo();
            window.setTimeout(() => startScreenDemo(id), 40);
        });

        document.querySelectorAll("[data-play]").forEach((button) => {
            button.addEventListener("click", () => {
                const id = button.dataset.play;
                if (player.running && player.screen === id && !player.paused) {
                    pauseDemo();
                    return;
                }
                if (player.running && player.screen === id && player.paused) {
                    resumeDemo();
                    return;
                }
                if (player.running) {
                    stopDemo();
                }
                startScreenDemo(id);
            });
        });

        const initial = location.hash.replace("#", "");
        showScreen(canSee(initial) ? initial : allowedScreens[0]);
        if (prevButton) {
            showStep(0);
        }
        resetDemoFields();
        demoBar.hidden = true;
        document.querySelectorAll("[data-play]").forEach((button) => {
            button.textContent = "播放演示";
        });
    </script>
</body>
</html>
