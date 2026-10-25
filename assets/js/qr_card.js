/* The preview SVG is the source for both the complete PNG and printed card. */
async function downloadCardPNG() {
    const button = document.getElementById('downloadCardBtn');
    const status = document.getElementById('cardDownloadStatus');
    const svg = document.querySelector('#qrCard > svg');
    if (!svg) return;
    button.disabled = true;
    status.textContent = '';
    const sourceURL = URL.createObjectURL(new Blob(
        [new XMLSerializer().serializeToString(svg)], { type: 'image/svg+xml;charset=utf-8' }
    ));
    try {
        const image = new Image();
        image.src = sourceURL;
        await image.decode();
        const canvas = document.createElement('canvas');
        canvas.width = 1200;
        canvas.height = 1800;
        const context = canvas.getContext('2d');
        if (!context) throw new Error('Canvas unavailable');
        context.fillStyle = 'white';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(image, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
        if (!blob) throw new Error('PNG unavailable');
        const downloadURL = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = downloadURL;
        link.download = 'student-attendance-card.png';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(downloadURL), 10000);
    } catch (error) {
        status.textContent = 'PNG download could not be created. Use Download Card SVG or Print Card.';
    } finally {
        URL.revokeObjectURL(sourceURL);
        button.disabled = false;
    }
}

function printCard() {
    const svg = document.querySelector('#qrCard > svg');
    if (!svg) return;
    const printWindow = window.open('', '_blank', 'width=450,height=700');
    if (!printWindow) {
        document.getElementById('cardDownloadStatus').textContent = 'Allow pop-ups to print the card.';
        return;
    }
    printWindow.document.title = 'Student Attendance Card';
    const style = printWindow.document.createElement('style');
    style.textContent = 'body{margin:0;padding:20px;display:flex;justify-content:center;background:white}'
        + 'svg{width:85mm;height:auto;max-width:100%}@media print{body{padding:0}}';
    printWindow.document.head.appendChild(style);
    // Load a self-contained image so photo decoding completes before printing.
    const image = printWindow.document.createElement('img');
    image.style.cssText = 'width:85mm;height:auto;max-width:100%';
    image.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(new XMLSerializer().serializeToString(svg));
    image.onload = () => {
        printWindow.onafterprint = () => printWindow.close();
        printWindow.focus();
        printWindow.print();
    };
    printWindow.document.body.appendChild(image);
}
