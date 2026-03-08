export function validateUploadCandidate(file, policy) {
  if (!policy) {
    return null;
  }

  if (Number.isFinite(policy.max_file_size_bytes) && file.size > policy.max_file_size_bytes) {
    return `"${file.name}" is too large. The current upload limit is ${policy.max_file_size_mb} MB per file.`;
  }

  const allowedExtensions = Array.isArray(policy.allowed_extensions) ? policy.allowed_extensions : [];

  if (allowedExtensions.length === 0) {
    return null;
  }

  const parts = file.name.toLowerCase().split('.');
  const extension = parts.length > 1 ? parts.at(-1) : '';

  if (!extension || !allowedExtensions.includes(extension)) {
    return `"${file.name}" is not allowed. Accepted types: ${policy.allowed_extensions_label}.`;
  }

  return null;
}
