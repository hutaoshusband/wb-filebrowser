import { validateUploadCandidate } from '../../frontend/src/lib/uploadPolicy.js';

describe('validateUploadCandidate', () => {
  it('allows very large files when the app-level upload cap is disabled', () => {
    const policy = {
      max_file_size_mb: 0,
      max_file_size_bytes: null,
      max_file_size_label: 'No app limit',
      has_app_limit: false,
      allowed_extensions: [],
      allowed_extensions_label: 'Any file type',
    };

    expect(validateUploadCandidate({ name: 'large-backup.tar', size: 5_000_000_000 }, policy)).toBeNull();
  });

  it('uses the upload limit label when rejecting a file', () => {
    const policy = {
      max_file_size_mb: 64,
      max_file_size_bytes: 64 * 1024 * 1024,
      max_file_size_label: '64 MB',
      has_app_limit: true,
      allowed_extensions: [],
      allowed_extensions_label: 'Any file type',
    };

    expect(validateUploadCandidate({ name: 'video.mov', size: 128 * 1024 * 1024 }, policy))
      .toContain('64 MB');
  });
});
