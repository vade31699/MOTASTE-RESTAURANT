const staffCredentials = {
    Admin: [
        { email: 'vadevidad31699@gmail.com', password: 'admin123' }
    ]
};

function validateStaffLogin(role, email, password, credentials = staffCredentials) {
    const normalizedRole = role || '';
    const normalizedEmail = (email || '').trim().toLowerCase();
    const activeCredentials = (typeof window !== 'undefined' && Array.isArray(window.motasteStaffAccounts) && window.motasteStaffAccounts.length)
        ? window.motasteStaffAccounts.reduce((groups, account) => {
            const accountRole = account.role || '';
            if (!groups[accountRole]) {
                groups[accountRole] = [];
            }
            groups[accountRole].push({ email: account.email, password: account.password });
            return groups;
        }, {})
        : credentials;
    const matchingRoleCredentials = activeCredentials[normalizedRole] || [];

    return matchingRoleCredentials.some((entry) => {
        return entry.email.toLowerCase() === normalizedEmail && entry.password === password;
    });
}

if (typeof window !== 'undefined') {
    window.validateStaffLogin = validateStaffLogin;
}

if (typeof module !== 'undefined') {
    module.exports = {
        staffCredentials,
        validateStaffLogin
    };
}
