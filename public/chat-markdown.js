(() => {
    const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

    const inline = (text) => {
        const codes = [];
        let out = esc(text).replace(/`([^`]+)`/g, (_, c) => `\u0000${codes.push(c) - 1}\u0000`);
        out = out
            .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>')
            .replace(/\*\*([^*]+)\*\*|__([^_]+)__/g, (_, a, b) => `<strong>${a || b}</strong>`)
            .replace(/(^|[^*\w])\*([^*\s][^*]*)\*(?!\*)/g, '$1<em>$2</em>')
            .replace(/(^|[^_\w])_([^_\s][^_]*)_(?![_\w])/g, '$1<em>$2</em>')
            .replace(/~~([^~]+)~~/g, '<del>$1</del>');
        return out.replace(/\u0000(\d+)\u0000/g, (_, i) => `<code>${codes[i]}</code>`);
    };

    const render = (source) => {
        const lines = String(source ?? '').replace(/\r\n?/g, '\n').split('\n');
        const html = [];
        let i = 0;

        while (i < lines.length) {
            const line = lines[i];

            if (/^```/.test(line)) {
                const code = [];
                i++;
                while (i < lines.length && !/^```/.test(lines[i])) code.push(lines[i++]);
                i++;
                html.push(`<pre><code>${esc(code.join('\n'))}</code></pre>`);
                continue;
            }
            if (!line.trim()) { i++; continue; }

            const heading = line.match(/^(#{1,6})\s+(.*)$/);
            if (heading) {
                const level = Math.min(heading[1].length + 2, 6);
                html.push(`<h${level}>${inline(heading[2])}</h${level}>`);
                i++;
                continue;
            }
            if (/^\s*([-*_])\s*\1\s*\1[\s\-*_]*$/.test(line)) { html.push('<hr>'); i++; continue; }

            if (/^\s*>/.test(line)) {
                const quote = [];
                while (i < lines.length && /^\s*>/.test(lines[i])) quote.push(lines[i++].replace(/^\s*>\s?/, ''));
                html.push(`<blockquote>${inline(quote.join('\n')).replace(/\n/g, '<br>')}</blockquote>`);
                continue;
            }

            const listMatch = line.match(/^\s*([-*+]|\d+[.)])\s+/);
            if (listMatch) {
                const ordered = /\d/.test(listMatch[1]);
                const items = [];
                while (i < lines.length) {
                    const m = lines[i].match(/^\s*([-*+]|\d+[.)])\s+(.*)$/);
                    if (m && /\d/.test(m[1]) === ordered) {
                        items.push(m[2]);
                        i++;
                    } else if (items.length && /^\s{2,}\S/.test(lines[i])) {
                        items[items.length - 1] += '\n' + lines[i++].trim();
                    } else break;
                }
                const tag = ordered ? 'ol' : 'ul';
                html.push(`<${tag}>${items.map((t) => `<li>${inline(t).replace(/\n/g, '<br>')}</li>`).join('')}</${tag}>`);
                continue;
            }

            if (/^\s*\|.*\|\s*$/.test(line) && /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/.test(lines[i + 1] || '')) {
                const cells = (l) => l.trim().replace(/^\||\|$/g, '').split('|').map((c) => c.trim());
                const head = cells(line);
                i += 2;
                const rows = [];
                while (i < lines.length && /^\s*\|.*\|\s*$/.test(lines[i])) rows.push(cells(lines[i++]));
                html.push(`<div class="chat-md-table"><table><thead><tr>${head.map((c) => `<th>${inline(c)}</th>`).join('')}</tr></thead><tbody>${rows.map((r) => `<tr>${r.map((c) => `<td>${inline(c)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`);
                continue;
            }

            const para = [];
            while (i < lines.length && lines[i].trim() && !/^(```|#{1,6}\s|\s*>|\s*([-*+]|\d+[.)])\s)/.test(lines[i])) para.push(lines[i++]);
            if (!para.length) para.push(lines[i++]);
            html.push(`<p>${inline(para.join('\n')).replace(/\n/g, '<br>')}</p>`);
        }
        return html.join('');
    };

    window.renderChatMarkdown = render;
})();
