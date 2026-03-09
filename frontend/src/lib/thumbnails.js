let pdfJsPromise;

async function loadPdfJs() {
  if (!pdfJsPromise) {
    pdfJsPromise = Promise.all([
      import('pdfjs-dist'),
      import('pdfjs-dist/build/pdf.worker.min.mjs?url'),
    ]).then(([pdfjs, workerSrc]) => {
      pdfjs.GlobalWorkerOptions.workerSrc = workerSrc.default;
      return pdfjs;
    });
  }

  return pdfJsPromise;
}

export async function renderPdfThumbnail(url, options = {}) {
  const { maxWidth = 320 } = options;
  const pdfjs = await loadPdfJs();
  const loadingTask = pdfjs.getDocument({
    url,
    withCredentials: true,
  });

  try {
    const pdf = await loadingTask.promise;
    const page = await pdf.getPage(1);
    const initialViewport = page.getViewport({ scale: 1 });
    const scale = maxWidth / Math.max(initialViewport.width, 1);
    const viewport = page.getViewport({ scale });
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d');

    if (!context) {
      throw new Error('Canvas rendering is unavailable.');
    }

    canvas.width = Math.ceil(viewport.width);
    canvas.height = Math.ceil(viewport.height);

    await page.render({
      canvasContext: context,
      viewport,
    }).promise;

    return canvas.toDataURL('image/png');
  } finally {
    loadingTask.destroy();
  }
}
