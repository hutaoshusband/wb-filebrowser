export function getSearchConfig(shell, section) {
  if (shell !== 'admin') {
    return {
      enabled: true,
      placeholder: 'Search files and folders...',
      emptyText: 'Search the current library.',
    };
  }

  switch (section) {
    case 'users':
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
    case 'settings':
      return {
        enabled: false,
        placeholder: 'Search is not used in Settings',
        emptyText: 'Use the settings tabs to move between Access, Uploads, Automation, and Security.',
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
      body: 'Guests can only browse folders you explicitly publish. Upload stays admin-only for guest access.',
    };
  }

  const user = users.find((entry) => Number(entry.id) === Number(principalId));

  return {
    title: user ? `Permissions for ${user.username}` : 'Permissions for a selected user',
    body: user
      ? 'These rules decide which folders this user can open and where uploads are allowed.'
      : 'Choose a user account to review folder access and upload rights.',
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
