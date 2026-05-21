const panelRight = document.getElementById('panel-right');
document.getElementById('close-right').addEventListener('click', () => panelRight.classList.add('hidden'));

export function showInfo(title, html) {
    document.getElementById('info-title').textContent = title;
    document.getElementById('info-content').innerHTML = html;
    panelRight.classList.remove('hidden');
    panelRight.scrollTop = 0;
}

export function irow(label, val) {
    if (val === null || val === undefined || val === '') return '';
    return `<div class="info-row"><span class="info-label">${label}</span><span class="info-value">${val}</span></div>`;
}
