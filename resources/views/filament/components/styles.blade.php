<style>
/* Document status bar (draft → posted → cancelled), shown at the top right of every document */
.bb-statusbar{display:flex;flex-wrap:wrap;gap:0;justify-content:flex-end}
.bb-statusbar span{position:relative;padding:6px 18px 6px 22px;font-size:13px;font-weight:600;background:rgb(241 245 249);color:rgb(100 116 139);clip-path:polygon(0 0,calc(100% - 10px) 0,100% 50%,calc(100% - 10px) 100%,0 100%,10px 50%);margin-inline-start:-6px}
[dir=rtl] .bb-statusbar span{clip-path:polygon(10px 0,100% 0,calc(100% - 10px) 50%,100% 100%,10px 100%,0 50%)}
.bb-statusbar span.on{background:rgb(15 118 110);color:#fff}
.bb-statusbar span.bad{background:rgb(225 29 72);color:#fff}
.dark .bb-statusbar span{background:rgb(30 41 59);color:rgb(148 163 184)}
.dark .bb-statusbar span.on{background:rgb(20 184 166);color:#042f2e}
/* Smart buttons */
.bb-smart{display:flex;flex-wrap:wrap;gap:8px}
.bb-smart a{display:flex;align-items:center;gap:8px;border:1px solid rgb(226 232 240);border-radius:8px;padding:6px 12px;font-size:13px;color:inherit;min-width:140px}
.bb-smart a:hover{background:rgb(240 253 250);border-color:rgb(15 118 110)}
.bb-smart b{display:block;font-size:15px}
.dark .bb-smart a{border-color:rgb(51 65 85)}
.dark .bb-smart a:hover{background:rgb(4 47 46)}
/* Chatter-style activity log */
.bb-chatter{display:flex;flex-direction:column;gap:14px}
.bb-chatter .msg{display:grid;grid-template-columns:34px 1fr;gap:10px}
.bb-chatter .av{width:34px;height:34px;border-radius:50%;background:rgb(204 251 241);color:rgb(15 118 110);display:grid;place-items:center;font-weight:700}
.bb-chatter .meta{font-size:12px;color:rgb(100 116 139)}
.bb-chatter .what{font-size:14px}
.bb-chatter .chg{font-size:12px;color:rgb(71 85 105);margin-top:2px}
/* Report tables */
.bb-report{width:100%;border-collapse:collapse;font-size:14px;font-variant-numeric:tabular-nums}
.bb-report th,.bb-report td{padding:7px 10px;border-bottom:1px solid rgb(226 232 240);text-align:start;white-space:nowrap}
.bb-report th{font-weight:600;color:rgb(71 85 105);background:rgb(248 250 252)}
.bb-report td.n,.bb-report th.n{text-align:end}
.bb-report tr.total td{font-weight:700;border-top:2px solid currentColor}
.bb-report tr.head td{font-weight:700;background:rgb(240 253 250)}
.bb-report a{color:rgb(15 118 110)}
.dark .bb-report th{background:rgb(15 23 42);color:rgb(148 163 184)}
.dark .bb-report th,.dark .bb-report td{border-color:rgb(51 65 85)}
.dark .bb-report tr.head td{background:rgb(4 47 46)}
.bb-scroll{overflow-x:auto}
.bb-ok{color:rgb(5 150 105);font-weight:700}.bb-bad{color:rgb(225 29 72);font-weight:700}
/* Denser lists, closer to a classic ERP */
.fi-ta-text{padding-top:.45rem!important;padding-bottom:.45rem!important}
.fi-ta-header-cell{padding-top:.6rem!important;padding-bottom:.6rem!important}
</style>
