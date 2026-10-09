/**
 * Silk Wave Animated Background for Chat Header
 *
 * Renders an animated wave canvas inside .aicwp-chat-header
 * via an absolutely-positioned inner wrapper (to avoid clipping avatar).
 * Hardcoded wave parameters, configurable base color.
 *
 * @package AICWP
 */

var AicwpSilkWave = (function() {
    'use strict';

    var instance = null;

    // Hardcoded wave parameters
    var CFG = {
        waves: 4,
        amplitude: 0.19,
        curviness: 3,
        softness: 0.5,
        blur: 25,
        speed: 0.7,
        flow: 1.05,
        grain: 0.15
    };

    // --- Color helpers ---
    function hexToHsl(hex) {
        var r = parseInt(hex.slice(1,3),16)/255, g = parseInt(hex.slice(3,5),16)/255, b = parseInt(hex.slice(5,7),16)/255;
        var max = Math.max(r,g,b), min = Math.min(r,g,b), h, s, l = (max+min)/2;
        if (max === min) { h = s = 0; } else {
            var d = max - min;
            s = l > 0.5 ? d/(2-max-min) : d/(max+min);
            if (max === r) h = ((g-b)/d + (g<b?6:0))/6;
            else if (max === g) h = ((b-r)/d + 2)/6;
            else h = ((r-g)/d + 4)/6;
        }
        return [h*360, s*100, l*100];
    }

    function hslToHex(h, s, l) {
        h = ((h%360)+360)%360; s = Math.max(0,Math.min(100,s))/100; l = Math.max(0,Math.min(100,l))/100;
        var c = (1-Math.abs(2*l-1))*s, x = c*(1-Math.abs((h/60)%2-1)), m = l-c/2, r, g, b;
        if(h<60){r=c;g=x;b=0;}else if(h<120){r=x;g=c;b=0;}else if(h<180){r=0;g=c;b=x;}
        else if(h<240){r=0;g=x;b=c;}else if(h<300){r=x;g=0;b=c;}else{r=c;g=0;b=x;}
        var toH = function(v) { var hex = Math.round((v+m)*255).toString(16); return hex.length===1?'0'+hex:hex; };
        return '#'+toH(r)+toH(g)+toH(b);
    }

    function deriveColors(baseHex, darkMode) {
        var hsl = hexToHsl(baseHex), h = hsl[0], s = hsl[1], l = hsl[2];
        if (darkMode) {
            return {
                c1: hslToHex(h+3, Math.min(s+15,100), Math.min(l+12, 45)),
                c2: baseHex,
                c3: hslToHex(h-4, Math.max(s,30), Math.max(l-20, 5)),
                c4: hslToHex(h-6, Math.max(s-10,20), Math.max(l-35, 2))
            };
        }
        return {
            c1: hslToHex(h+2, Math.min(s+10,100), Math.max(l-10,20)),
            c2: baseHex,
            c3: hslToHex(h-3, Math.max(s,25), Math.min(l+15,75)),
            c4: hslToHex(h-5, Math.max(s-10,20), Math.min(l+32,89))
        };
    }

    function hexToRgb(hex) { return [parseInt(hex.slice(1,3),16), parseInt(hex.slice(3,5),16), parseInt(hex.slice(5,7),16)]; }
    function lerpC(a,b,t) { return [a[0]+(b[0]-a[0])*t, a[1]+(b[1]-a[1])*t, a[2]+(b[2]-a[2])*t]; }
    function rgb(c,a) { if(a===undefined)a=1; return 'rgba('+Math.round(c[0])+','+Math.round(c[1])+','+Math.round(c[2])+','+a+')'; }

    /**
     * Pre-compute RGB colors from a base hex color.
     */
    function computeColors(baseHex, darkMode) {
        var colors = deriveColors(baseHex, darkMode);
        return {
            c1: hexToRgb(colors.c1),
            c2: hexToRgb(colors.c2),
            c3: hexToRgb(colors.c3),
            c4: hexToRgb(colors.c4)
        };
    }

    // --- Wave math ---
    function waveFunc(x, t, seed) {
        return Math.sin(x*1.0+t+seed)
            + Math.sin(x*0.6+t*1.3+seed*2.1)*0.6
            + Math.sin(x*1.7+t*0.7+seed*0.7)*0.3
            + Math.sin(x*0.3+t*1.8+seed*3.2)*0.4;
    }

    function buildPath(wi, time, numW, W, H) {
        var pts = [], segs = 80;
        var baseY = H * (0.15 + 0.7 * (wi / (numW - 0.5)));
        var seed = wi * 3.7;
        var ampBoost = Math.max(1, 400 / H);
        var amp = H * CFG.amplitude * ampBoost * (0.6 + 0.8 * Math.sin(seed));
        for (var j = 0; j <= segs; j++) {
            var t = j / segs;
            pts.push({ x: t * W, y: baseY + waveFunc(t * CFG.curviness * Math.PI, time * CFG.flow, seed) * amp * 0.5 });
        }
        return pts;
    }

    /**
     * Draw a single frame of the wave animation.
     */
    function drawFrame(ctx, W, H, time, cc, grain) {
        // Background gradient
        var bg = ctx.createLinearGradient(0, 0, W * 0.5, H);
        bg.addColorStop(0, rgb(cc.c1));
        bg.addColorStop(0.5, rgb(lerpC(cc.c1, cc.c2, 0.5)));
        bg.addColorStop(1, rgb(lerpC(cc.c2, cc.c3, 0.3)));
        ctx.fillStyle = bg;
        ctx.fillRect(0, 0, W, H);

        // Scale blur proportionally to canvas height (reference: 400px)
        var scaledBlur = CFG.blur * (H / 400);
        if (scaledBlur > 0) ctx.filter = 'blur(' + scaledBlur + 'px)';

        for (var wi = 0; wi < CFG.waves; wi++) {
            var path = buildPath(wi, time, CFG.waves, W, H);
            var ct = wi / (CFG.waves - 1);
            var wc;
            if (ct < 0.33) wc = lerpC(cc.c1, cc.c2, ct / 0.33);
            else if (ct < 0.66) wc = lerpC(cc.c2, cc.c3, (ct - 0.33) / 0.33);
            else wc = lerpC(cc.c3, cc.c4, (ct - 0.66) / 0.34);

            var rc = lerpC(wc, cc.c4, 0.4);
            var bw = H * CFG.softness;

            // Wave band
            ctx.beginPath();
            ctx.moveTo(path[0].x, path[0].y - bw);
            for (var j = 1; j < path.length - 1; j++) {
                var xc = (path[j].x + path[j+1].x) / 2, yc = (path[j].y + path[j+1].y) / 2;
                ctx.quadraticCurveTo(path[j].x, path[j].y - bw * 0.3, xc, yc - bw * 0.3);
            }
            for (var k = path.length - 1; k >= 1; k--) {
                var xc2 = (path[k].x + path[k-1].x) / 2, yc2 = (path[k].y + path[k-1].y) / 2;
                ctx.quadraticCurveTo(path[k].x, path[k].y + bw, xc2, yc2 + bw);
            }
            ctx.closePath();

            var avgY = path.reduce(function(s, p) { return s + p.y; }, 0) / path.length;
            var gr = ctx.createLinearGradient(0, avgY - bw, 0, avgY + bw);
            gr.addColorStop(0, rgb(wc, 0));
            gr.addColorStop(0.25, rgb(rc, 0.75));
            gr.addColorStop(0.5, rgb(rc, 0.95));
            gr.addColorStop(0.75, rgb(wc, 0.65));
            gr.addColorStop(1, rgb(wc, 0));
            ctx.fillStyle = gr;
            ctx.fill();

            // Broad glow
            ctx.beginPath();
            ctx.moveTo(path[0].x, path[0].y);
            for (var m = 1; m < path.length - 1; m++) {
                var gx = (path[m].x + path[m+1].x) / 2, gy = (path[m].y + path[m+1].y) / 2;
                ctx.quadraticCurveTo(path[m].x, path[m].y, gx, gy);
            }
            ctx.strokeStyle = rgb(lerpC(rc, cc.c4, 0.3), 0.25);
            ctx.lineWidth = bw * 0.8;
            ctx.lineCap = 'round';
            ctx.stroke();

            // Thin highlight
            ctx.beginPath();
            ctx.moveTo(path[0].x, path[0].y);
            for (var n = 1; n < path.length - 1; n++) {
                var hx = (path[n].x + path[n+1].x) / 2, hy = (path[n].y + path[n+1].y) / 2;
                ctx.quadraticCurveTo(path[n].x, path[n].y, hx, hy);
            }
            ctx.strokeStyle = rgb(cc.c4, 0.18);
            ctx.lineWidth = bw * 0.3;
            ctx.stroke();
        }

        ctx.filter = 'none';

        // Vignette top-right
        var vg = ctx.createRadialGradient(W * 0.8, 0, 0, W * 0.8, 0, W * 0.7);
        vg.addColorStop(0, rgb(cc.c1, 0.3));
        vg.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = vg;
        ctx.fillRect(0, 0, W, H);

        // Glow bottom-left
        var gl = ctx.createRadialGradient(W * 0.1, H, 0, W * 0.1, H, W * 0.6);
        gl.addColorStop(0, rgb(cc.c4, 0.15));
        gl.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = gl;
        ctx.fillRect(0, 0, W, H);

        if (grain) grain.style.opacity = CFG.grain;
    }

    /**
     * Initialize the silk wave inside a container element.
     * Creates an inner wrapper so overflow:hidden doesn't clip avatar.
     *
     * @param {HTMLElement} headerEl - The container element
     * @param {string} baseColor - Hex color, e.g. '#1560d0'
     */
    function init(headerEl, baseColor) {
        destroy();
        if (!headerEl) return;

        // Store current state for setColor/setDarkMode
        var currentColor = baseColor;
        // Auto-detect dark mode from DOM (ancestor with .dark-mode class)
        var isDark = !!headerEl.closest('.dark-mode');

        // Pre-compute colors once
        var cc = computeColors(currentColor, isDark);

        // Create wrapper (positioned absolute, overflow hidden, behind content)
        var wrap = document.createElement('div');
        wrap.className = 'aicwp-silk-wave-wrap';

        var canvas = document.createElement('canvas');
        canvas.className = 'aicwp-silk-wave-canvas';
        wrap.appendChild(canvas);

        headerEl.insertBefore(wrap, headerEl.firstChild);

        var grain = document.createElement('div');
        grain.className = 'aicwp-silk-wave-grain';
        headerEl.appendChild(grain);

        var ctx = canvas.getContext('2d');
        var W = 0, H = 0, time = 0, raf = null, running = true, skipFrame = false;

        function resize() {
            var dpr = Math.min(window.devicePixelRatio || 1, 2);
            var rect = wrap.getBoundingClientRect();
            W = rect.width; H = rect.height;
            if (W > 0 && H > 0) {
                canvas.width = W * dpr;
                canvas.height = H * dpr;
                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            }
        }

        function draw() {
            if (!running) return;

            // If container not visible yet, keep trying
            if (W === 0 || H === 0) {
                resize();
                raf = requestAnimationFrame(draw);
                return;
            }

            // Render at ~30fps (skip every other frame)
            skipFrame = !skipFrame;
            if (skipFrame) { raf = requestAnimationFrame(draw); return; }

            time += CFG.speed * 0.016;
            // Prevent floating point drift over long sessions
            if (time > 10000) time = 0;

            drawFrame(ctx, W, H, time, cc, grain);
            raf = requestAnimationFrame(draw);
        }

        resize();
        draw();

        var resizeHandler = function() { resize(); };
        window.addEventListener('resize', resizeHandler);

        instance = {
            wrap: wrap,
            grain: grain,
            headerEl: headerEl,
            resizeHandler: resizeHandler,
            stop: function() { running = false; if (raf) { cancelAnimationFrame(raf); raf = null; } },
            start: function() { if (!running) { running = true; resize(); draw(); } },
            setColor: function(newColor) { currentColor = newColor; cc = computeColors(currentColor, isDark); },
            setDarkMode: function(dark) { isDark = !!dark; cc = computeColors(currentColor, isDark); }
        };
    }

    /**
     * Update the base color without re-initializing.
     */
    function setColor(newColor) {
        if (instance && instance.setColor) {
            instance.setColor(newColor);
        }
    }

    /**
     * Switch between light and dark color derivation.
     */
    function setDarkMode(dark) {
        if (instance && instance.setDarkMode) {
            instance.setDarkMode(dark);
        }
    }

    /**
     * Stop the animation (e.g. when popup hidden).
     */
    function stop() {
        if (instance) instance.stop();
    }

    /**
     * Resume the animation (e.g. when popup shown).
     */
    function start() {
        if (instance) instance.start();
    }

    /**
     * Remove wrapper, stop animation, clean up.
     */
    function destroy() {
        if (!instance) return;
        instance.stop();
        window.removeEventListener('resize', instance.resizeHandler);
        if (instance.wrap && instance.wrap.parentNode) {
            instance.wrap.parentNode.removeChild(instance.wrap);
        }
        if (instance.grain && instance.grain.parentNode) {
            instance.grain.parentNode.removeChild(instance.grain);
        }
        instance = null;
    }

    return {
        init: init,
        destroy: destroy,
        stop: stop,
        start: start,
        setColor: setColor,
        setDarkMode: setDarkMode
    };

})();
