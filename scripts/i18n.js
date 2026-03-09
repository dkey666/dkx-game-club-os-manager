(function () {
    const SUPPORTED_LANGUAGES = ['ru', 'uz', 'en'];
    const LOCALE_MAP = {
        ru: 'ru-RU',
        uz: 'uz-UZ',
        en: 'en-US'
    };

    const RANKS = {
        1: {
            name: { ru: 'Новичок I', uz: 'Yangi I', en: 'Novice I' },
            range: { ru: '0 - 4,999 сум', uz: '0 - 4,999 so\'m', en: '0 - 4,999 UZS' }
        },
        2: {
            name: { ru: 'Страж I', uz: 'Qo\'riqchi I', en: 'Guard I' },
            range: { ru: '5,000 - 9,999 сум', uz: '5,000 - 9,999 so\'m', en: '5,000 - 9,999 UZS' }
        },
        3: {
            name: { ru: 'Страж II', uz: 'Qo\'riqchi II', en: 'Guard II' },
            range: { ru: '10,000 - 19,999 сум', uz: '10,000 - 19,999 so\'m', en: '10,000 - 19,999 UZS' }
        },
        4: {
            name: { ru: 'Воин I', uz: 'Jangchi I', en: 'Warrior I' },
            range: { ru: '20,000 - 39,999 сум', uz: '20,000 - 39,999 so\'m', en: '20,000 - 39,999 UZS' }
        },
        5: {
            name: { ru: 'Воин II', uz: 'Jangchi II', en: 'Warrior II' },
            range: { ru: '40,000 - 79,999 сум', uz: '40,000 - 79,999 so\'m', en: '40,000 - 79,999 UZS' }
        },
        6: {
            name: { ru: 'Воин III', uz: 'Jangchi III', en: 'Warrior III' },
            range: { ru: '80,000 - 149,999 сум', uz: '80,000 - 149,999 so\'m', en: '80,000 - 149,999 UZS' }
        },
        7: {
            name: { ru: 'Чемпион I', uz: 'Chempion I', en: 'Champion I' },
            range: { ru: '150,000 - 299,999 сум', uz: '150,000 - 299,999 so\'m', en: '150,000 - 299,999 UZS' }
        },
        8: {
            name: { ru: 'Чемпион II', uz: 'Chempion II', en: 'Champion II' },
            range: { ru: '300,000 - 599,999 сум', uz: '300,000 - 599,999 so\'m', en: '300,000 - 599,999 UZS' }
        },
        9: {
            name: { ru: 'Легенда', uz: 'Afsona', en: 'Legend' },
            range: { ru: '600,000 - 999,999 сум', uz: '600,000 - 999,999 so\'m', en: '600,000 - 999,999 UZS' }
        },
        10: {
            name: { ru: 'Властелин Арены', uz: 'Arena Lordi', en: 'Arena Lord' },
            range: { ru: '1,000,000+ сум', uz: '1,000,000+ so\'m', en: '1,000,000+ UZS' }
        }
    };

    function normalizeLanguage(language) {
        const value = String(language || '').trim().toLowerCase();
        if (value.startsWith('uz')) {
            return 'uz';
        }
        if (value.startsWith('en')) {
            return 'en';
        }
        return 'ru';
    }

    function getLanguageFromPath(pathname) {
        const match = String(pathname || '').match(/^\/(uz|en)(?:\/|$)/i);
        return match ? normalizeLanguage(match[1]) : null;
    }

    function getLanguageFromQuery(search) {
        const params = new URLSearchParams(search || window.location.search);
        const value = params.get('lang');
        return value ? normalizeLanguage(value) : null;
    }

    function detectLanguage() {
        const queryLanguage = getLanguageFromQuery();
        if (queryLanguage) {
            return queryLanguage;
        }

        const pathLanguage = getLanguageFromPath(window.location.pathname);
        if (pathLanguage) {
            return pathLanguage;
        }

        const storedLanguage = normalizeLanguage(localStorage.getItem('selectedLanguage'));
        if (SUPPORTED_LANGUAGES.includes(storedLanguage)) {
            return storedLanguage;
        }

        const htmlLanguage = normalizeLanguage(document.documentElement.lang);
        if (SUPPORTED_LANGUAGES.includes(htmlLanguage)) {
            return htmlLanguage;
        }

        return normalizeLanguage(navigator.language);
    }

    let currentLanguage = detectLanguage();

    function getCurrentLanguage() {
        return currentLanguage;
    }

    function setCurrentLanguage(language) {
        currentLanguage = normalizeLanguage(language);
        localStorage.setItem('selectedLanguage', currentLanguage);
        document.documentElement.lang = currentLanguage;
        return currentLanguage;
    }

    function localeFor(language) {
        return LOCALE_MAP[normalizeLanguage(language)] || LOCALE_MAP.ru;
    }

    function resolvePath(path) {
        return String(path || window.location.pathname + window.location.search + window.location.hash).replace(/^\/(uz|en)(?=\/|$)/i, '') || '/';
    }

    function withLanguage(path, language) {
        const lang = setCurrentLanguage(language || currentLanguage);
        const url = new URL(resolvePath(path), window.location.origin);

        if (lang === 'ru') {
            url.searchParams.delete('lang');
        } else {
            url.searchParams.set('lang', lang);
        }

        return url.pathname + url.search + url.hash;
    }

    function navigate(path, language) {
        window.location.href = withLanguage(path, language);
    }

    function switchLanguage(language) {
        navigate(window.location.pathname + window.location.search + window.location.hash, language);
    }

    function resolveKey(source, key) {
        return String(key || '')
            .split('.')
            .reduce((value, part) => (value && typeof value === 'object') ? value[part] : undefined, source);
    }

    function createTranslator(dictionary) {
        return function translate(key, params) {
            const values = dictionary || {};
            const lang = getCurrentLanguage();
            let text = resolveKey(values[lang], key);

            if (text === undefined) {
                text = resolveKey(values.ru, key);
            }

            if (text === undefined) {
                return key;
            }

            if (typeof text !== 'string') {
                return text;
            }

            return text.replace(/\{(\w+)\}/g, function (_, token) {
                return params && params[token] !== undefined ? String(params[token]) : `{${token}}`;
            });
        };
    }

    function translateRankName(name, level, language) {
        const lang = normalizeLanguage(language || currentLanguage);
        if (level && RANKS[level]) {
            return RANKS[level].name[lang] || RANKS[level].name.ru;
        }

        const entry = Object.values(RANKS).find(function (rank) {
            return Object.values(rank.name).includes(name);
        });

        return entry ? (entry.name[lang] || entry.name.ru) : name;
    }

    function translateRankRange(level, language) {
        const lang = normalizeLanguage(language || currentLanguage);
        return RANKS[level] ? (RANKS[level].range[lang] || RANKS[level].range.ru) : '';
    }

    function formatNumber(value, options) {
        return Number(value || 0).toLocaleString(localeFor(currentLanguage), options);
    }

    function formatDate(value, options) {
        return new Date(value).toLocaleDateString(localeFor(currentLanguage), options);
    }

    function formatDateTime(value, options) {
        return new Date(value).toLocaleString(localeFor(currentLanguage), options);
    }

    setCurrentLanguage(currentLanguage);

    window.DKXI18n = {
        createTranslator,
        formatDate,
        formatDateTime,
        formatNumber,
        getCurrentLanguage,
        localeFor,
        navigate,
        normalizeLanguage,
        switchLanguage,
        translateRankName,
        translateRankRange,
        withLanguage
    };
})();
