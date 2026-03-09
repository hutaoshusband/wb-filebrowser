export function getSearchConfig(shell, section, options = {}) {
  if (shell !== 'admin') {
    return {
      enabled: true,
      placeholder: 'Search files and folders...',
      emptyText: 'Search the current library.',
    };
  }

  switch (section) {
    case 'users':
      if (Number(options.userId) > 0) {
        return {
          enabled: true,
          placeholder: 'Filter folder permissions...',
          emptyText: 'Search the selected user permission matrix by folder path.',
        };
      }

      return {
        enabled: true,
        placeholder: 'Search users...',
        emptyText: 'Filter the user list by username.',
      };
    case 'permissions':
      return {
        enabled: true,
        placeholder: 'Filter folders...',
        emptyText: 'Filter the permission matrix by folder path.',
      };
    case 'audit':
      return {
        enabled: true,
        placeholder: 'Search audit logs...',
        emptyText: 'Search audit events by event key, user, IP, or target.',
      };
    case 'security':
      return {
        enabled: false,
        placeholder: 'Search is not used in Security',
        emptyText: 'Review audit settings, storage health, and banned IP addresses.',
      };
    case 'settings':
      return {
        enabled: false,
        placeholder: 'Search is not used in Settings',
        emptyText: 'Use the settings tabs to move between Access, Display, Uploads, and Automation.',
      };
    default:
      return {
        enabled: false,
        placeholder: 'Search is not used on Dashboard',
        emptyText: 'Use the quick actions below to move through the admin surface.',
      };
  }
}

export function filterUsers(users, query, section) {
  if (section !== 'users') {
    return users;
  }

  const needle = query.trim().toLowerCase();

  if (needle === '') {
    return users;
  }

  return users.filter((user) => user.username.toLowerCase().includes(needle));
}

export function filterPermissionRows(rows, query) {
  const needle = query.trim().toLowerCase();

  if (needle === '') {
    return rows;
  }

  return rows.filter((row) => row.path.toLowerCase().includes(needle));
}

export function describePermissionPrincipal(type, principalId, users) {
  if (type === 'guest') {
    return {
      title: 'Published folders for guests',
      body: 'Guests can only browse folders you explicitly publish. User-specific edit, delete, and create rights are managed from each user detail page.',
    };
  }

  const user = users.find((entry) => Number(entry.id) === Number(principalId));

  return {
    title: user ? `Permissions for ${user.username}` : 'Permissions for a selected user',
    body: user
      ? 'These rules decide which folders this user can open, upload into, edit, delete, and extend with new folders.'
      : 'Choose a user account to review folder access and management rights.',
  };
}

export function jobTone(job) {
  if (job.last_result === 'error') {
    return 'danger';
  }

  if (job.last_result === 'warning') {
    return 'warning';
  }

  return 'success';
}
