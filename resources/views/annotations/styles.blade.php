<style>
    @font-face {
        font-family: Outfit;
        font-style: normal;
        font-weight: 100 900;
        font-display: swap;
        src: url("{{ $headingFontDataUri }}") format("woff2");
    }
    @font-face {
        font-family: 'Source Sans 3';
        font-style: normal;
        font-weight: 200 900;
        font-display: swap;
        src: url("{{ $bodyFontDataUri }}") format("woff2");
    }
    :root {
        color-scheme: light;
        --font-heading: Outfit, system-ui, sans-serif;
        --font-body: 'Source Sans 3', system-ui, sans-serif;
        --color-background: #f6f6f1;
        --color-surface: #ffffff;
        --color-foreground: #252422;
        --color-muted: #65675c;
        --color-border: #dedfd5;
        --color-primary: #657a3c;
        --color-primary-hover: #50632f;
        --color-accent: #f0f3e8;
        --color-pass: #24643b;
        --color-pass-surface: #eaf4ec;
        --color-fail: #a13732;
        --color-fail-surface: #fcefed;
        --color-unknown: #7c5a18;
        --color-unknown-surface: #faf2dc;
        --color-ring: #657a3c;
        --radius-sm: 6px;
        --radius-md: 10px;
        --radius-lg: 14px;
    }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--color-background); color: var(--color-foreground); font: 15px/1.55 var(--font-body); -webkit-font-smoothing: antialiased; }
    a { color: var(--color-primary); text-underline-offset: 4px; }
    :focus-visible { outline: 3px solid var(--color-ring); outline-offset: 4px; }
    .skip-link { position: absolute; top: -100px; left: 16px; padding: 12px 20px; background: var(--color-surface); z-index: 1; }
    .skip-link:focus { top: 8px; }
    header { background: var(--color-surface); border-bottom: 1px solid var(--color-border); }
    .header-inner { max-width: 1280px; margin: auto; padding: 20px 32px; display: flex; align-items: center; gap: 24px; }
    .brand { display: inline-flex; align-items: center; gap: 10px; flex-shrink: 0; text-decoration: none; color: var(--color-foreground); font: 650 30px/1 var(--font-heading); letter-spacing: -.02em; font-kerning: normal; }
    .brand img { display: block; width: 44px; height: 44px; }
    .header-section { border-left: 1px solid var(--color-border); padding-left: 24px; color: var(--color-muted); font: 500 15px/1.4 var(--font-heading); }
    .back-link { margin-left: auto; font-size: 13px; text-decoration: none; }
    main { max-width: 1280px; margin: 40px auto 64px; padding: 0 32px; }
    main:focus { outline: none; }
    h1, h2, h3, p { margin: 0; overflow-wrap: anywhere; }
    h1, h2, h3 { font-family: var(--font-heading); font-weight: 600; }
    h1 { font-size: 34px; line-height: 1.2; letter-spacing: -.025em; margin-top: 8px; }
    h2 { font-size: 21px; line-height: 1.4; letter-spacing: -.015em; }
    h3 { font-size: 14px; }
    .muted { color: var(--color-muted); }
    .small { font-size: 12px; }
    .eyebrow { font-family: var(--font-heading); font-size: 11px; font-weight: 600; letter-spacing: 1.3px; text-transform: uppercase; color: var(--color-muted); }
    .intro { display: flex; justify-content: space-between; align-items: center; gap: 24px; margin-bottom: 32px; }
    .intro p { margin-top: 12px; }
    .counts { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .layout { display: grid; grid-template-columns: 256px minmax(0, 1fr); gap: 32px; align-items: start; }
    .layout > *, .choices { min-width: 0; }
    .queue-heading { margin: 4px 0 16px 12px; }
    .queue { display: grid; gap: 8px; max-height: 640px; overflow: auto; padding: 4px; }
    .case { display: block; padding: 16px; text-decoration: none; border: 1px solid transparent; border-radius: var(--radius-md); color: var(--color-foreground); }
    .case strong { display: block; font-size: 13px; font-weight: 600; overflow-wrap: anywhere; margin-bottom: 8px; }
    .case:hover { background: var(--color-surface); border-color: var(--color-border); }
    .case[aria-current=page] { background: var(--color-accent); border-color: #abc5b0; box-shadow: inset 3px 0 var(--color-primary); }
    .tag { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; line-height: 1.5; border-radius: var(--radius-sm); background: #edf0ed; color: var(--color-muted); padding: 4px 8px; overflow-wrap: anywhere; }
    .tag.pending, .tag.unknown { color: var(--color-unknown); background: var(--color-unknown-surface); }
    .tag.pass { color: var(--color-pass); background: var(--color-pass-surface); }
    .tag.fail { color: var(--color-fail); background: var(--color-fail-surface); }
    .panel { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 28px; margin-bottom: 20px; }
    .panel-heading, .source-heading { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
    .case-title { font-size: 26px; margin-top: 16px; }
    .policy-block { margin-top: 24px; padding-bottom: 24px; border-bottom: 1px solid var(--color-border); }
    .policy-block code { margin-left: 8px; color: var(--color-primary); font-size: 12px; font-weight: 400; }
    .policy { margin-top: 8px; color: #46554b; }
    code, pre { overflow-wrap: anywhere; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; }
    pre { font-size: 12px; line-height: 1.75; white-space: pre-wrap; margin: 0; max-height: 300px; overflow: auto; }
    details { margin: 20px 0; }
    summary { cursor: pointer; font-size: 12px; font-weight: 600; }
    details pre { padding-top: 12px; color: var(--color-muted); }
    .sources { background: var(--color-background); border: 1px solid var(--color-border); border-radius: var(--radius-md); margin-top: 16px; overflow: hidden; }
    .source-heading { padding: 12px 16px; border-bottom: 1px solid var(--color-border); }
    .sources pre { padding: 16px; }
    .choices { border: 0; padding: 0; margin: 20px 0 24px; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
    .choices legend { font-size: 13px; font-weight: 500; margin-bottom: 12px; padding: 0; }
    .choice { --choice-color: var(--color-pass); --choice-surface: var(--color-pass-surface); display: grid; gap: 4px; border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 16px 12px; cursor: pointer; }
    .choice.fail { --choice-color: var(--color-fail); --choice-surface: var(--color-fail-surface); }
    .choice.unknown { --choice-color: var(--color-unknown); --choice-surface: var(--color-unknown-surface); }
    .choice:hover { border-color: var(--choice-color); }
    .choice:has(input:checked) { background: var(--choice-surface); border-color: var(--choice-color); box-shadow: 0 0 0 1px var(--choice-color); }
    .choice small { font-size: 12px; color: var(--color-muted); margin-left: 24px; }
    input { accent-color: var(--choice-color); margin: 0 6px 0 0; }
    .note-heading { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; font-size: 12px; }
    textarea { display: block; width: 100%; border: 1px solid #acb9ae; border-radius: var(--radius-md); background: var(--color-surface); color: var(--color-foreground); padding: 12px 16px; font: inherit; resize: vertical; margin: 8px 0 20px; }
    textarea::placeholder { color: var(--color-muted); }
    button { border: 1px solid var(--color-primary); border-radius: var(--radius-md); background: var(--color-primary); color: white; padding: 12px 20px; min-height: 44px; font: 600 14px var(--font-body); cursor: pointer; }
    button span { margin-left: 16px; }
    button:hover { background: var(--color-primary-hover); }
    .form-footer, .export-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
    .export-row { padding: 0 4px; font-size: 12px; }
    .notice { background: var(--color-pass-surface); color: var(--color-pass); border: 1px solid #abc5b0; border-radius: var(--radius-md); padding: 12px 16px; margin-bottom: 24px; }
    .error { color: var(--color-fail); background: var(--color-fail-surface); border-color: #e8b7b0; }
    .empty { text-align: center; padding: 56px 24px; }
    .empty p { margin: 12px auto 0; max-width: 360px; }
    .empty-mark { display: inline-grid; place-items: center; width: 44px; height: 44px; border: 1px solid var(--color-border); border-radius: var(--radius-md); color: var(--color-muted); font-size: 24px; margin-bottom: 20px; }
    .page-links { display: flex; justify-content: space-between; gap: 16px; margin: 16px 12px; font-size: 12px; }
    .dataset-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr)); gap: 20px; }
    .dataset-card { display: block; text-decoration: none; color: var(--color-foreground); }
    .dataset-card h2 { margin: 12px 0 32px; }
    .dataset-card:hover { border-color: var(--color-primary); }
    .dataset-action { display: flex; justify-content: space-between; color: var(--color-primary); font-size: 12px; }
    .case-navigation { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 16px; font-size: 13px; }
    .case-navigation a { text-decoration: none; }
    kbd { display: inline-block; border: 1px solid var(--color-border); border-bottom-width: 2px; border-radius: 4px; padding: 1px 5px; font: 11px/1.5 var(--font-body); color: var(--color-muted); background: var(--color-background); white-space: nowrap; }
    .choice kbd { margin-left: 4px; }
    .shortcut-help { margin-top: 20px; border-top: 1px solid var(--color-border); padding-top: 16px; font-size: 12px; }
    .shortcut-settings { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 8px; }
    .shortcut-settings label { cursor: pointer; }
    .shortcut-settings input { accent-color: var(--color-primary); }
    #review-draft { color: var(--color-unknown); }
    [hidden] { display: none !important; }
    @media (max-width: 760px) {
        .header-inner { padding: 16px 20px; gap: 16px; }
        .brand { gap: 8px; font-size: 26px; }
        .brand img { width: 36px; height: 36px; }
        .header-section { padding-left: 16px; font-size: 12px; }
        .back-link { font-size: 0; }
        .back-link span { font-size: 18px; }
        main { margin: 24px auto 40px; padding: 0 16px; }
        h1 { font-size: 30px; }
        .intro { align-items: start; flex-direction: column; gap: 16px; margin-bottom: 24px; }
        .layout { grid-template-columns: minmax(0, 1fr); gap: 24px; }
        .queue { max-height: 224px; }
        .case { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 12px; }
        .case strong { margin: 0; }
        .tag { flex-shrink: 0; }
        .panel { padding: 20px; }
        .case-title { font-size: 23px; }
        .choices { grid-template-columns: 1fr; }
        .choice { grid-template-columns: 150px minmax(0, 1fr); align-items: center; padding: 16px; }
        .choice small { margin: 0; }
        .form-footer button { width: 100%; }
    }
</style>
