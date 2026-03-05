/**
 * Генератор PNG-иконок для расширения.
 *
 * Запуск: откройте generate-icons.html в браузере
 * и нажмите кнопку "Сгенерировать". Иконки скачаются автоматически.
 *
 * Альтернативно: используйте service worker для динамической генерации
 * иконок через OffscreenCanvas (см. ниже).
 */

// Эта функция может быть использована в service worker
// для динамической генерации иконок без статических PNG.
function createIconCanvas(size, state) {
    const canvas = new OffscreenCanvas(size, size);
    const ctx = canvas.getContext('2d');

    const colors = {
        gray:  { bg: '#6b7280', accent: '#9ca3af' },
        red:   { bg: '#e74c3c', accent: '#c0392b' },
        green: { bg: '#27ae60', accent: '#1e8449' },
    };

    const c = colors[state];
    const cx = size / 2;
    const cy = size / 2;
    const r = size * 0.42;

    // Прозрачный фон
    ctx.clearRect(0, 0, size, size);

    // Внешний круг
    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.fillStyle = c.bg;
    ctx.fill();

    // Внутренний круг
    ctx.beginPath();
    ctx.arc(cx, cy, r * 0.78, 0, Math.PI * 2);
    ctx.fillStyle = c.accent;
    ctx.fill();

    // Буква "К"
    ctx.fillStyle = '#ffffff';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.font = `bold ${Math.round(size * 0.38)}px Arial, sans-serif`;
    ctx.fillText('К', cx, cy + 1);

    // Блик
    ctx.beginPath();
    ctx.arc(cx - r * 0.2, cy - r * 0.25, r * 0.22, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(255, 255, 255, 0.12)';
    ctx.fill();

    return canvas;
}

// Для использования в service worker:
// const canvas = createIconCanvas(32, 'red');
// const blob = await canvas.convertToBlob({ type: 'image/png' });
// const imageData = ctx.getImageData(0, 0, size, size);
// chrome.action.setIcon({ tabId, imageData: { 32: imageData } });
