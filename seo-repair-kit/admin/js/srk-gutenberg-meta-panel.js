// ============================================================================
// SEO REPAIR KIT - COMPLETE EDITOR INTEGRATION (GUTENBERG + CLASSIC)
// SHARED UTILITIES FOR BOTH EDITORS
// ============================================================================
// Version: 2.1.3
// Author: SEO Repair Kit Team
// Description: Handles Gutenberg and Classic Editor integration for SEO meta fields
// ============================================================================

// Make Gutenberg components available globally
window.SRK_Gutenberg = {
    Modal: null,
    Button: null,
    element: null
};
// ============================================================================
// PART 1: GUTENBERG EDITOR COMPONENTS WITH ADVANCED TABS
// ============================================================================
(function (wp) {
    'use strict';

    console.log(' Checking WordPress packages for Gutenberg...');

    // Check for required packages
    if (!wp.plugins || !wp.editPost || !wp.element || !wp.components) {
        console.error(' Required WordPress packages not available - Skipping Gutenberg');
        return;
    }

    const { registerPlugin } = wp.plugins;
    const { PluginDocumentSettingPanel } = wp.editPost;
    const {
        TextControl,
        Button,
        Modal,
        BaseControl,
        Spinner,
        Snackbar,
        ToggleControl,
        Notice,
        TabPanel,
        SelectControl,
        CheckboxControl
    } = wp.components;
    const { useState, useEffect, useRef, useCallback, createElement, render, unmountComponentAtNode } = wp.element;
    const { useSelect, useDispatch } = wp.data;
    const { __ } = wp.i18n;

    console.log('✅ All Gutenberg packages available');

    // Store Gutenberg components globally for Classic Editor to use
    window.SRK_Gutenberg.Modal = Modal;
    window.SRK_Gutenberg.Button = Button;
    window.SRK_Gutenberg.element = wp.element;
    window.SRK_Gutenberg.components = wp.components;

    // SHARED TEMPLATE PROCESSING FUNCTION - CORRECTED VERSION
    const processTemplate = (template, data = {}) => {
        if (!template) return '';
        const srkData = window.srkGutenbergData || {};
        const separator = srkData.separator || '-';
        const siteName = srkData.siteName || '';
        const siteDescription = srkData.siteDescription || '';
        const postType = data.postType || srkData.postType || 'post';

        // IMPORTANT: Get actual post data from WordPress if available
        const postTitle = data.postTitle || srkData.postTitle || (window.wp.data.select('core/editor').getCurrentPost()?.title?.rendered || 'Sample Post Title');
        const postExcerpt = data.postExcerpt || srkData.postExcerpt || (window.wp.data.select('core/editor').getCurrentPost()?.excerpt?.rendered || 'Sample excerpt from a page/post.');

        let processed = template;

        // First, replace post-type specific tags (e.g., %title%, %title%)
        processed = processed
            .replace(new RegExp(`%title%`, 'gi'), postTitle)
            .replace(new RegExp(`%excerpt%`, 'gi'), postExcerpt);

        // Also replace generic post tags with actual values
        processed = processed
            .replace(/%title%/gi, postTitle)
            .replace(/%excerpt%/gi, postExcerpt)
            .replace(/%sep%/gi, separator)
            .replace(/%site_title%/gi, siteName)
            .replace(/%sitedesc%/gi, siteDescription)
            .replace(/%title%/gi, data.pageTitle || postTitle) // Use post title as page title fallback
            .replace(/%author_first_name%/gi, data.authorFirstName || srkData.authorFirstName || '')
            .replace(/%author_last_name%/gi, data.authorLastName || srkData.authorLastName || '')
            .replace(/%author_name%/gi, data.authorName || srkData.authorName || '')
            .replace(/%categories%/gi, data.categories || srkData.categories || '')
            .replace(/%term_title%/gi, data.categoryTitle || srkData.categoryTitle || '')
            .replace(/%date%/gi, data.currentDate || srkData.currentDate || '')
            .replace(/%day%/gi, data.currentDay || srkData.currentDay || '')
            .replace(/%month%/gi, data.currentMonth || srkData.currentMonth || '')
            .replace(/%year%/gi, data.currentYear || srkData.currentYear || '')
            .replace(/%custom_field%/gi, data.customField || srkData.customField || '')
            .replace(/%permalink%/gi, data.permalink || srkData.permalink || '')
            .replace(/%content%/gi, data.postContent || srkData.postContent || '')
            .replace(/%post_date%/gi, data.postDate || srkData.postDate || '')
            .replace(/%post_day%/gi, data.postDay || srkData.postDay || '');

        // Clean up multiple spaces
        processed = processed.replace(/\s+/g, ' ').trim();
        return processed;
    };
    // ============================================================================
    // SHARED UTILITY FUNCTIONS
    // ============================================================================

    /**
     * Get preview data for template processing - SHARED between Gutenberg and Classic
     */
    const getPreviewData = () => {
        const srkData = window.srkGutenbergData || {};

        // Try to get current post data from WordPress if available
        let currentPost = null;
        try {
            if (window.wp && window.wp.data) {
                currentPost = window.wp.data.select('core/editor').getCurrentPost();
            }
        } catch (e) {
            console.log('Could not get current post from WordPress data');
        }

        return {
            postTitle: currentPost?.title?.rendered || srkData.postTitle || 'Sample Post Title',
            postExcerpt: currentPost?.excerpt?.rendered || srkData.postExcerpt || 'Sample excerpt from a page/post.',
            pageTitle: currentPost?.title?.rendered || 'Sample Page Title',
            authorFirstName: srkData.authorFirstName || '',
            authorLastName: srkData.authorLastName || '',
            authorName: srkData.authorName || '',
            categories: srkData.categories || '',
            categoryTitle: srkData.categoryTitle || '',
            currentDate: srkData.currentDate || new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }),
            currentDay: srkData.currentDay || new Date().getDate().toString(),
            currentMonth: srkData.currentMonth || new Date().toLocaleDateString('en-US', { month: 'long' }),
            currentYear: srkData.currentYear || new Date().getFullYear().toString(),
            customField: srkData.customField || '',
            permalink: srkData.permalink || '',
            postContent: srkData.postContent || '',
            postDate: srkData.postDate || '',
            postDay: srkData.postDay || '',
            postType: srkData.postType || 'post'
        };
    };

    // Make it globally available for Classic Editor
    window.SRK_getPreviewData = getPreviewData;

    // Sync Notification Component
    const SyncNotification = ({ message, type = 'info' }) => {
        const { createNotice, removeNotice } = useDispatch('core/notices');

        useEffect(() => {
            if (message) {
                const noticeId = 'srk-sync-notice-' + Date.now();

                createNotice(
                    type,
                    message,
                    {
                        id: noticeId,
                        type: 'snackbar',
                        isDismissible: true
                    }
                );

                // Auto remove after 3 seconds
                const timer = setTimeout(() => {
                    removeNotice(noticeId);
                }, 3000);

                return () => {
                    clearTimeout(timer);
                    removeNotice(noticeId);
                };
            }
        }, [message, type, createNotice, removeNotice]);

        return null;
    };

    // TagModal Component
    const TagModal = ({
        isOpen,
        onClose,
        onSelectTag,
        onDeleteTag,
        currentTarget,
        editingTag,
        tags,
        relevantTags,
        modalTitle,
        showDeleteButton = false
    }) => {
        const [searchTerm, setSearchTerm] = useState('');
        const [filteredTags, setFilteredTags] = useState([]);

        useEffect(() => {
            if (!tags) return;

            if (searchTerm.trim() === '') {
                // Show relevant tags first, then all tags
                const relevant = relevantTags[currentTarget] || {};
                const relevantTagValues = Object.values(relevant);

                const sortedTags = Object.entries(tags).sort(([nameA, dataA], [nameB, dataB]) => {
                    const isRelevantA = relevantTagValues.includes(dataA.tag);
                    const isRelevantB = relevantTagValues.includes(dataB.tag);

                    if (isRelevantA && !isRelevantB) return -1;
                    if (!isRelevantA && isRelevantB) return 1;
                    return nameA.localeCompare(nameB);
                });

                setFilteredTags(sortedTags);
            } else {
                const filtered = Object.entries(tags).filter(([name, data]) => {
                    const nameMatch = name.toLowerCase().includes(searchTerm.toLowerCase());
                    const descMatch = data.description.toLowerCase().includes(searchTerm.toLowerCase());
                    return nameMatch || descMatch;
                });
                setFilteredTags(filtered);
            }
        }, [searchTerm, tags, currentTarget, relevantTags]);

        if (!isOpen) return null;

        const ModalHeader = () => {
            return createElement('div', {
                style: {
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                    padding: '16px 24px',
                    borderBottom: '1px solid #ddd'
                }
            },
                createElement('div', {
                    style: {
                        display: 'flex',
                        alignItems: 'center',
                        gap: '15px'
                    }
                },
                    createElement('h2', {
                        style: {
                            margin: 0,
                            fontSize: '20px',
                            fontWeight: 600,
                            lineHeight: '1.4'
                        }
                    }, modalTitle || (editingTag ? __('Replace or Delete Tag', 'seo-repair-kit') : __('Select a Tag', 'seo-repair-kit'))),

                    editingTag && createElement(Button, {
                        icon: 'trash',
                        label: __('Delete Tag', 'seo-repair-kit'),
                        onClick: () => {
                            onDeleteTag();
                            onClose();
                        },
                        isDestructive: true,
                        style: {
                            padding: '6px',
                            minWidth: '32px',
                            height: '32px',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center'
                        }
                    })
                )
            );
        };

        return createElement(Modal, {
            title: '',
            onRequestClose: onClose,
            className: 'srk-tag-modal',
            style: { maxWidth: '500px' },
            headerActions: ModalHeader()
        },
            createElement('div', { style: { padding: '20px' } },
                createElement('div', { style: { marginBottom: '20px' } },
                    createElement('input', {
                        type: 'text',
                        className: 'srk-search-input',
                        placeholder: __('Search for an item...', 'seo-repair-kit'),
                        value: searchTerm,
                        onChange: (e) => setSearchTerm(e.target.value),
                        style: {
                            width: '100%',
                            padding: '8px 12px',
                            border: '1px solid #ddd',
                            borderRadius: '4px',
                            fontSize: '14px'
                        },
                        autoFocus: true
                    })
                ),

                createElement('ul', {
                    style: {
                        maxHeight: '300px',
                        overflowY: 'auto',
                        margin: 0,
                        padding: 0,
                        listStyle: 'none',
                        border: '1px solid #eee',
                        borderRadius: '4px'
                    }
                },
                    filteredTags.map(([name, data]) =>
                        createElement('li', {
                            key: data.tag,
                            className: `srk-tag-item ${editingTag === data.tag ? 'selected' : ''}`,
                            onClick: () => {
                                onSelectTag(data.tag);
                                onClose();
                            },
                            style: {
                                padding: '10px 15px',
                                borderBottom: '1px solid #eee',
                                cursor: 'pointer',
                                display: 'flex',
                                alignItems: 'flex-start',
                                backgroundColor: editingTag === data.tag ? '#f0f9ff' : 'transparent'
                            }
                        },
                            createElement('span', {
                                style: {
                                    display: 'inline-flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    width: '20px',
                                    height: '20px',
                                    borderRadius: '50%',
                                    background: '#007cba',
                                    color: 'white',
                                    marginRight: '10px',
                                    fontSize: '12px',
                                    fontWeight: 'bold',
                                    flexShrink: 0
                                }
                            }, '+'),
                            createElement('div', { style: { flex: 1 } },
                                createElement('h4', {
                                    style: {
                                        margin: '0 0 5px 0',
                                        fontSize: '14px',
                                        fontWeight: 'bold'
                                    }
                                }, name),
                                createElement('p', {
                                    style: {
                                        margin: 0,
                                        fontSize: '12px',
                                        color: '#666',
                                        lineHeight: '1.4'
                                    }
                                }, data.description)
                            )
                        )
                    )
                ),

                !editingTag && createElement('div', {
                    style: {
                        display: 'flex',
                        justifyContent: 'flex-end',
                        marginTop: '20px',
                        paddingTop: '20px',
                        borderTop: '1px solid #eee'
                    }
                },
                    createElement(Button, {
                        isSecondary: true,
                        onClick: onClose
                    }, __('Cancel', 'seo-repair-kit'))
                )
            )
        );
    };

    // Store TagModal globally for Classic Editor
    window.SRK_TagModal = TagModal;

    // Visual Tag Input Component
    const VisualTagInput = ({ value, onChange, placeholder, type = 'title', label, showDefaults = false, onResetToDefault }) => {
        const [isTagModalOpen, setIsTagModalOpen] = useState(false);
        const [isEditModalOpen, setIsEditModalOpen] = useState(false);
        const [editingTag, setEditingTag] = useState(null);
        const [editingTagIndex, setEditingTagIndex] = useState(-1);
        const inputRef = useRef(null);

        const parseSegments = useCallback((val) => {
            if (!val) return [];

            const segments = [];
            const tagRegex = /%[a-z_]+%/gi;
            let lastEnd = 0;
            let match;
            let tagIndex = 0;

            while ((match = tagRegex.exec(val)) !== null) {
                if (match.index > lastEnd) {
                    segments.push({
                        type: 'text',
                        value: val.substring(lastEnd, match.index)
                    });
                }
                segments.push({
                    type: 'tag',
                    value: match[0],
                    start: match.index,
                    end: match.index + match[0].length,
                    index: tagIndex++
                });
                lastEnd = match.index + match[0].length;
            }

            if (lastEnd < val.length) {
                segments.push({ type: 'text', value: val.substring(lastEnd) });
            }

            return segments;
        }, []);

        const handleTagClick = useCallback((tag, tagIndex) => {
            setEditingTag(tag);
            setEditingTagIndex(tagIndex);
            setIsEditModalOpen(true);
        }, []);

        const handleInsertTag = useCallback((tag) => {
            const currentValue = value || '';

            if (editingTag !== null && editingTagIndex >= 0) {
                const segments = parseSegments(currentValue);
                let newValue = '';
                let currentTagIndex = 0;

                for (const segment of segments) {
                    if (segment.type === 'tag') {
                        if (segment.index === editingTagIndex) {
                            newValue += tag;
                        } else {
                            newValue += segment.value;
                        }
                        currentTagIndex++;
                    } else {
                        newValue += segment.value;
                    }
                }

                onChange(newValue);
                setEditingTag(null);
                setEditingTagIndex(-1);
            } else {
                const cursorPos = inputRef.current ? inputRef.current.selectionStart : currentValue.length;
                const before = currentValue.substring(0, cursorPos);
                const after = currentValue.substring(cursorPos);
                const newValue = before + (before && !before.endsWith(' ') ? ' ' : '') + tag + ' ' + after;
                onChange(newValue);
            }
        }, [value, editingTag, editingTagIndex, parseSegments, onChange]);

        const handleDeleteTag = useCallback(() => {
            if (editingTag !== null && editingTagIndex >= 0) {
                const currentValue = value || '';
                const segments = parseSegments(currentValue);
                let newValue = '';
                let currentTagIndex = 0;

                for (const segment of segments) {
                    if (segment.type === 'tag') {
                        if (segment.index !== editingTagIndex) {
                            newValue += segment.value;
                        }
                        currentTagIndex++;
                    } else {
                        newValue += segment.value;
                    }
                }

                onChange(newValue.replace(/\s+/g, ' ').trim());
                setEditingTag(null);
                setEditingTagIndex(-1);
            }
        }, [editingTag, editingTagIndex, value, parseSegments, onChange]);

        const getRelevantTags = useCallback(() => {
            const relevantTags = window.srkGutenbergData?.templateTagsRelevant || {};
            return relevantTags[type] || {};
        }, [type]);

        const getAllTags = useCallback(() => {
            return window.srkGutenbergData?.templateTags || {};
        }, []);

        const segments = parseSegments(value || '');
        const relevantTags = getRelevantTags();

        return createElement('div', { style: { marginBottom: '20px' } },
            createElement('div', {
                style: {
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                    marginBottom: '8px'
                }
            },
                createElement('label', {
                    style: {
                        fontWeight: 'bold',
                        fontSize: '13px'
                    }
                }, label || (type === 'title' ? __('SEO Title', 'seo-repair-kit') : __('Meta Description', 'seo-repair-kit'))),

                showDefaults && onResetToDefault && createElement(Button, {
                    isSmall: true,
                    isSecondary: true,
                    onClick: onResetToDefault,
                    style: { fontSize: '12px', height: '24px' }
                }, __('Reset to Default', 'seo-repair-kit'))
            ),

            createElement('div', {
                style: {
                    marginBottom: '8px',
                    display: 'flex',
                    flexWrap: 'wrap',
                    alignItems: 'center',
                    gap: '4px'
                }
            },
                Object.entries(relevantTags).map(([tagName, tag]) =>
                    createElement('span', {
                        key: tag,
                        className: 'srk-tag srk-tag-btn',
                        'data-tag': tag,
                        onClick: () => handleInsertTag(tag),
                        style: {
                            cursor: 'pointer',
                            padding: '3px 6px',
                            background: '#eee',
                            borderRadius: '3px',
                            fontSize: '12px',
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: '4px'
                        }
                    },
                        createElement('span', { style: { fontWeight: 'bold' } }, '+'),
                        createElement('span', null, tagName)
                    )
                ),

                createElement('a', {
                    href: '#',
                    className: 'srk-view-all-tags',
                    onClick: (e) => {
                        e.preventDefault();
                        setEditingTag(null);
                        setEditingTagIndex(-1);
                        setIsTagModalOpen(true);
                    },
                    style: {
                        marginLeft: '8px',
                        fontSize: '12px',
                        color: '#007cba',
                        textDecoration: 'none'
                    }
                }, __('View all tags →', 'seo-repair-kit'))
            ),

            createElement('div', {
                className: 'srk-tag-input-visual-wrapper',
                onClick: () => inputRef.current && inputRef.current.focus(),
                style: {
                    border: '1px solid #ddd',
                    borderRadius: '4px',
                    padding: '8px 12px',
                    minHeight: '40px',
                    background: '#fff',
                    cursor: 'text'
                }
            },
                segments.length === 0 ? createElement('span', {
                    style: { color: '#999' }
                }, placeholder) : null,

                segments.map((segment, index) => {
                    if (segment.type === 'tag') {
                        const displayText = segment.value
                            .replace(/%/g, '')
                            .replace(/_/g, ' ')
                            .replace(/^srk /i, '')
                            .trim();
                        return createElement('span', {
                            key: index,
                            className: `srk-input-tag-chip ${editingTag === segment.value && editingTagIndex === segment.index ? 'active' : ''}`,
                            'data-tag': segment.value,
                            'data-tag-index': segment.index,
                            onClick: (e) => {
                                e.stopPropagation();
                                handleTagClick(segment.value, segment.index);
                            },
                            style: {
                                display: 'inline-flex',
                                alignItems: 'center',
                                background: '#f0f0f0',
                                borderRadius: '3px',
                                padding: '2px 6px 2px 8px',
                                margin: '0 2px',
                                fontSize: '12px',
                                lineHeight: '1.5',
                                border: '1px solid #ddd',
                                cursor: 'pointer',
                                userSelect: 'none'
                            }
                        },
                            createElement('span', {
                                style: { marginRight: '4px' }
                            }, displayText),
                            createElement('span', {
                                className: 'srk-tag-dropdown-icon',
                                style: {
                                    display: 'inline-flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    width: '16px',
                                    height: '16px'
                                }
                            },
                                createElement('svg', {
                                    width: '8',
                                    height: '8',
                                    viewBox: '0 0 8 8',
                                    fill: 'none',
                                    xmlns: 'http://www.w3.org/2000/svg'
                                },
                                    createElement('path', {
                                        d: 'M4 6L1 3H7L4 6Z',
                                        fill: '#333'
                                    })
                                )
                            )
                        );
                    } else {
                        return createElement('span', {
                            key: index,
                            style: { whiteSpace: 'pre-wrap' }
                        }, segment.value);
                    }
                }),

                createElement('input', {
                    type: 'text',
                    ref: inputRef,
                    value: value || '',
                    onChange: (e) => onChange(e.target.value),
                    style: {
                        position: 'absolute',
                        opacity: 0,
                        height: 0,
                        width: 0,
                        pointerEvents: 'none'
                    }
                })
            ),

            createElement('p', {
                className: 'description',
                style: {
                    margin: '4px 0 0 0',
                    fontSize: '12px',
                    color: '#666'
                }
            }, type === 'title' ? __('Title shown in search results', 'seo-repair-kit') : __('Description shown in search results', 'seo-repair-kit')),

            createElement(TagModal, {
                isOpen: isTagModalOpen,
                onClose: () => {
                    setIsTagModalOpen(false);
                    setEditingTag(null);
                    setEditingTagIndex(-1);
                },
                onSelectTag: (tag) => {
                    handleInsertTag(tag);
                    setIsTagModalOpen(false);
                },
                currentTarget: type,
                editingTag: null,
                tags: getAllTags(),
                relevantTags: getRelevantTags(),
                modalTitle: __('Select a Tag', 'seo-repair-kit')
            }),

            isEditModalOpen && createElement(TagModal, {
                isOpen: isEditModalOpen,
                onClose: () => {
                    setIsEditModalOpen(false);
                    setEditingTag(null);
                    setEditingTagIndex(-1);
                },
                onSelectTag: (tag) => {
                    handleInsertTag(tag);
                    setIsEditModalOpen(false);
                },
                onDeleteTag: () => {
                    handleDeleteTag();
                    setIsEditModalOpen(false);
                },
                currentTarget: type,
                editingTag: editingTag,
                tags: getAllTags(),
                relevantTags: getRelevantTags(),
                modalTitle: __('Replace or Delete Tag', 'seo-repair-kit'),
                showDeleteButton: true
            })
        );
    };

    // Advanced Settings Component WITH PROPER DEFAULT LOGIC - FIXED VERSION
    const AdvancedSettings = ({ settings, onChange, onApplyContentTypeSettings }) => {
        const [localSettings, setLocalSettings] = useState(settings || {});
        const srkData = window.srkGutenbergData || {};

        useEffect(() => {
            // Ensure settings is always an object with proper defaults
            const safeSettings = settings || {};

            // CRITICAL FIX: Default to ON (use_default_settings = '1') if not set
            // This means: follow content type settings (sync mode)
            const useDefault = safeSettings.use_default_settings === '0' ? false : true;

            setLocalSettings({
                use_default_settings: useDefault ? '1' : '0',
                show_meta_box: safeSettings.show_meta_box || '1',
                robots_meta: {
                    noindex: '0',
                    nofollow: '0',
                    noarchive: '0',
                    notranslate: '0',
                    noimageindex: '0',
                    nosnippet: '0',
                    noodp: '0',
                    max_snippet: -1,
                    max_video_preview: -1,
                    max_image_preview: 'large',
                    ...(safeSettings.robots_meta || {})
                }
            });
        }, [settings]);

        const handleChange = (key, value) => {
            const newSettings = { ...localSettings };
            if (key.includes('.')) {
                const [parent, child] = key.split('.');
                if (!newSettings[parent]) newSettings[parent] = {};
                newSettings[parent][child] = value;
            } else {
                newSettings[key] = value;
            }
            setLocalSettings(newSettings);
            onChange(newSettings);
        };

        const handleRobotsMetaChange = (key, value) => {
            const newSettings = { ...localSettings };
            if (!newSettings.robots_meta) newSettings.robots_meta = {};
            newSettings.robots_meta[key] = value;
            setLocalSettings(newSettings);
            onChange(newSettings);
        };

        const handleApplyContentTypeSettings = () => {
            if (confirm(srkData.i18n?.applyWarning || 'This will override your current advanced settings with the content type defaults. Continue?')) {
                onApplyContentTypeSettings();
            }
        };

        // CRITICAL FIX: Toggle is ON when use_default_settings = '1' (follow content type)
        // Toggle is OFF when use_default_settings = '0' (custom settings)
        const isUsingDefaults = localSettings.use_default_settings !== '0';

        // Check conditional fields
        const isNoSnippetChecked = localSettings.robots_meta?.nosnippet === '1';
        const isNoImageIndexChecked = localSettings.robots_meta?.noimageindex === '1';

        return createElement('div', { style: { padding: '10px 0' } },
            // Use Default Settings Toggle - FIXED UI
            createElement(BaseControl, {
                label: srkData.i18n?.useDefaultSettings || 'Use Content Type Defaults (Sync)',
                className: 'srk-field-control'
            },
                createElement(ToggleControl, {
                    label: srkData.i18n?.useDefaultSettings || 'Use Content Type Defaults (Sync)',
                    checked: isUsingDefaults, // ON = follow content type, OFF = custom
                    onChange: (value) => {
                        handleChange('use_default_settings', value ? '1' : '0');
                    }
                })
            ),

            // Show sync status message
            isUsingDefaults && createElement('div', {
                style: {
                    padding: '10px',
                    background: '#f0f9ff',
                    border: '1px solid #bae6fd',
                    borderRadius: '4px',
                    marginBottom: '15px',
                    fontSize: '13px',
                    color: '#0369a1'
                }
            },
                createElement('strong', null, 'Sync Mode: '),
                'Following Content Type settings from Meta Manager. Changes there will automatically apply here.'
            ),

            // Show custom settings ONLY when NOT using defaults (toggle OFF)
            !isUsingDefaults && createElement('div', { className: 'srk-custom-settings-container' },
                // Robots Meta Settings
                createElement('div', { style: { marginBottom: '20px' } },
                    createElement('h4', { style: { marginBottom: '10px' } },
                        srkData.i18n?.robotsMeta || 'Custom Robots Meta Settings'
                    ),

                    createElement('div', {
                        style: {
                            display: 'grid',
                            gridTemplateColumns: 'repeat(2, 1fr)',
                            gap: '10px',
                            marginBottom: '15px'
                        }
                    },
                        createElement(CheckboxControl, {
                            label: srkData.i18n?.noIndex || 'No Index',
                            checked: localSettings.robots_meta?.noindex === '1',
                            onChange: (value) => handleRobotsMetaChange('noindex', value ? '1' : '0')
                        }),
                        createElement(CheckboxControl, {
                            label: srkData.i18n?.noFollow || 'No Follow',
                            checked: localSettings.robots_meta?.nofollow === '1',
                            onChange: (value) => handleRobotsMetaChange('nofollow', value ? '1' : '0')
                        }),
                        createElement(CheckboxControl, {
                            label: srkData.i18n?.noArchive || 'No Archive',
                            checked: localSettings.robots_meta?.noarchive === '1',
                            onChange: (value) => handleRobotsMetaChange('noarchive', value ? '1' : '0')
                        }),
                        createElement(CheckboxControl, {
                            label: srkData.i18n?.noTranslate || 'No Translate',
                            checked: localSettings.robots_meta?.notranslate === '1',
                            onChange: (value) => handleRobotsMetaChange('notranslate', value ? '1' : '0')
                        }),
                        createElement(CheckboxControl, {
                            label: srkData.i18n?.noImageIndex || 'No Image Index',
                            checked: localSettings.robots_meta?.noimageindex === '1',
                            onChange: (value) => handleRobotsMetaChange('noimageindex', value ? '1' : '0')
                        }),
                        createElement(CheckboxControl, {
                            label: srkData.i18n?.noSnippet || 'No Snippet',
                            checked: localSettings.robots_meta?.nosnippet === '1',
                            onChange: (value) => handleRobotsMetaChange('nosnippet', value ? '1' : '0')
                        }),
                        createElement(CheckboxControl, {
                            label: srkData.i18n?.noOdp || 'No ODP',
                            checked: localSettings.robots_meta?.noodp === '1',
                            onChange: (value) => handleRobotsMetaChange('noodp', value ? '1' : '0')
                        })
                    ),

                    // Max Values - WITH CONDITIONAL HIDING
                    createElement('div', {
                        style: {
                            display: 'grid',
                            gridTemplateColumns: `repeat(${isNoSnippetChecked && isNoImageIndexChecked ? 1 :
                                isNoSnippetChecked || isNoImageIndexChecked ? 2 : 3}, 1fr)`,
                            gap: '15px',
                            marginTop: '15px'
                        }
                    },
                        // MAX SNIPPET - Hide when No Snippet is checked
                        !isNoSnippetChecked && createElement(BaseControl, {
                            label: srkData.i18n?.maxSnippet || 'Max Snippet',
                            className: 'srk-field-control'
                        },
                            createElement(TextControl, {
                                type: 'number',
                                value: localSettings.robots_meta?.max_snippet || -1,
                                onChange: (value) => handleRobotsMetaChange('max_snippet', value),
                                min: -1
                            })
                        ),

                        // MAX VIDEO PREVIEW - Always visible
                        createElement(BaseControl, {
                            label: srkData.i18n?.maxVideoPreview || 'Max Video Preview',
                            className: 'srk-field-control'
                        },
                            createElement(TextControl, {
                                type: 'number',
                                value: localSettings.robots_meta?.max_video_preview || -1,
                                onChange: (value) => handleRobotsMetaChange('max_video_preview', value),
                                min: -1
                            })
                        ),

                        // MAX IMAGE PREVIEW - Hide when No Image Index is checked
                        !isNoImageIndexChecked && createElement(BaseControl, {
                            label: srkData.i18n?.maxImagePreview || 'Max Image Preview',
                            className: 'srk-field-control'
                        },
                            createElement(SelectControl, {
                                value: localSettings.robots_meta?.max_image_preview || 'large',
                                options: [
                                    { label: srkData.i18n?.none || 'None', value: 'none' },
                                    { label: srkData.i18n?.standard || 'Standard', value: 'standard' },
                                    { label: srkData.i18n?.large || 'Large', value: 'large' }
                                ],
                                onChange: (value) => handleRobotsMetaChange('max_image_preview', value)
                            })
                        )
                    )
                )
            )
        );
    };

    // MAIN GUTENBERG SEO PANEL COMPONENT WITH TABS
    const SRKGutenbergSeoPanel = () => {
        console.log('🎯 Gutenberg SEO Panel Component Initialized with Tabs');

        const post = useSelect((select) => {
            try {
                return select('core/editor').getCurrentPost();
            } catch (error) {
                console.error('Error getting post:', error);
                return null;
            }
        }, []);

        const postType = useSelect((select) => {
            try {
                return select('core/editor').getCurrentPostType();
            } catch (e) {
                return post?.type || 'post';
            }
        }, [post]);

        const postId = post?.id || 0;
        const allowedTypes = (window.srkGutenbergConfig || window.srkGutenbergData || {}).allowedPostTypes || ['post', 'page', 'product'];
        if (allowedTypes.length && !allowedTypes.includes(postType)) {
            return null;
        }

        const postTitle = typeof post?.title === 'string' ? post.title : (post?.title?.rendered || '');
        const postExcerpt = typeof post?.excerpt === 'string' ? post.excerpt : (post?.excerpt?.rendered || '');

        // State
        const [metaTitle, setMetaTitle] = useState('');
        const [metaDescription, setMetaDescription] = useState('');
        const [canonicalUrl, setCanonicalUrl] = useState('');
        const [isModalOpen, setIsModalOpen] = useState(false);
        const [activeTab, setActiveTab] = useState('title-description');
        const [isSaving, setIsSaving] = useState(false);
        const [showSavedMessage, setShowSavedMessage] = useState(false);
        const [advancedSettings, setAdvancedSettings] = useState({});
        const [lastSyncTime, setLastSyncTime] = useState(0);
        const [hasFormChanges, setHasFormChanges] = useState(false);
        const [originalValues, setOriginalValues] = useState({
            metaTitle: '',
            metaDescription: '',
            canonicalUrl: '',
            advancedSettings: {}
        });
        const [syncNotification, setSyncNotification] = useState('');
        const [syncNotificationType, setSyncNotificationType] = useState('info');

        // Get meta data from post meta
        const postMeta = useSelect((select) => {
            return select('core/editor').getEditedPostAttribute('meta') || {};
        }, []);

        const srkData = window.srkGutenbergData || {};
        const defaultTitleTemplate = srkData.defaultTitleTemplate || '%title% %sep% %site_title%';
        const defaultDescTemplate = srkData.defaultDescTemplate || '%excerpt%';

        const { editPost, savePost } = useDispatch('core/editor');
        const { createNotice } = useDispatch('core/notices');

        // Load meta on mount - FIXED VERSION WITH PROPER DEFAULTS
        useEffect(() => {
            if (!postId) return;

            console.log('Loading meta for post:', postId);

            // Get advanced settings from post meta
            let loadedAdvancedSettings = {};

            if (postMeta._srk_advanced_settings && typeof postMeta._srk_advanced_settings === 'object') {
                loadedAdvancedSettings = postMeta._srk_advanced_settings;
            } else if (postMeta._srk_advanced_settings && typeof postMeta._srk_advanced_settings === 'string') {
                try {
                    loadedAdvancedSettings = JSON.parse(postMeta._srk_advanced_settings);
                } catch (e) {
                    console.error('Error parsing advanced settings:', e);
                }
            }

            // CRITICAL FIX: If no advanced settings saved, default to use_default_settings = '1'
            // This means: follow content type settings (sync mode)
            if (!loadedAdvancedSettings || Object.keys(loadedAdvancedSettings).length === 0) {
                loadedAdvancedSettings = {
                    use_default_settings: '1', // Default ON - follow content type
                    show_meta_box: '1',
                    robots_meta: srkData.contentTypeRobots || {
                        noindex: '0',
                        nofollow: '0',
                        noarchive: '0',
                        notranslate: '0',
                        noimageindex: '0',
                        nosnippet: '0',
                        noodp: '0',
                        max_snippet: -1,
                        max_video_preview: -1,
                        max_image_preview: 'large'
                    }
                };
            } else {
                // Ensure use_default_settings has a value, default to '1' if not set
                if (!loadedAdvancedSettings.use_default_settings) {
                    loadedAdvancedSettings.use_default_settings = '1';
                }
            }

            setAdvancedSettings(loadedAdvancedSettings);

            // Load other meta values - use content type templates as defaults if empty
            if (postMeta._srk_meta_title) {
                setMetaTitle(postMeta._srk_meta_title);
            } else {
                // Default to content type template
                const contentTypeTemplate = srkData.contentTypeSettings?.title || srkData.defaultTitleTemplate;
                setMetaTitle(contentTypeTemplate);
            }

            if (postMeta._srk_meta_description) {
                setMetaDescription(postMeta._srk_meta_description);
            } else {
                // Default to content type template
                const contentTypeTemplate = srkData.contentTypeSettings?.desc || srkData.defaultDescTemplate;
                setMetaDescription(contentTypeTemplate);
            }

            if (postMeta._srk_canonical_url) {
                setCanonicalUrl(postMeta._srk_canonical_url);
            }

            if (postMeta._srk_last_sync) {
                setLastSyncTime(parseInt(postMeta._srk_last_sync) || 0);
            }
            // Store original values for comparison
            setOriginalValues({
                metaTitle: metaTitle || '',
                metaDescription: metaDescription || '',
                canonicalUrl: canonicalUrl || '',
                advancedSettings: JSON.parse(JSON.stringify(advancedSettings))
            });
            setHasFormChanges(false);
        }, [postId, postMeta]);

        // Sync hidden fields for form submission compatibility (use_default=0 → custom robots persist)
        useEffect(() => {
            const $hidden = document.getElementById('srk_advanced_settings');
            if ($hidden && advancedSettings) {
                $hidden.value = JSON.stringify(advancedSettings);
            }
        }, [advancedSettings]);

        // Detect form changes
        useEffect(() => {
            if (!postId) return;

            const hasChanges =
                metaTitle !== originalValues.metaTitle ||
                metaDescription !== originalValues.metaDescription ||
                canonicalUrl !== originalValues.canonicalUrl ||
                JSON.stringify(advancedSettings) !== JSON.stringify(originalValues.advancedSettings);

            setHasFormChanges(hasChanges);

        }, [metaTitle, metaDescription, canonicalUrl, advancedSettings]);
        // REAL-TIME SYNC: Check for updates from Classic Editor
        useEffect(() => {
            if (!postId) return;

            const syncInterval = setInterval(() => {
                checkForUpdates();
            }, 5000);

            return () => clearInterval(syncInterval);
        }, [postId, metaTitle, metaDescription, canonicalUrl, lastSyncTime, advancedSettings]);

        const checkForUpdates = useCallback(async () => {
            try {
                const response = await wp.apiFetch({
                    path: `/srk/v1/meta/${postId}`,
                    method: 'GET'
                });

                if (response.last_sync > lastSyncTime) {
                    let changed = false;

                    if (response.meta_title !== metaTitle) {
                        setMetaTitle(response.meta_title || '');
                        changed = true;
                    }
                    if (response.meta_description !== metaDescription) {
                        setMetaDescription(response.meta_description || '');
                        changed = true;
                    }
                    if (response.canonical_url !== canonicalUrl) {
                        setCanonicalUrl(response.canonical_url || '');
                        changed = true;
                    }
                    if (JSON.stringify(response.advanced_settings) !== JSON.stringify(advancedSettings)) {
                        setAdvancedSettings(response.advanced_settings || {});
                        changed = true;
                    }

                    if (changed) {
                        editPost({
                            meta: {
                                _srk_meta_title: response.meta_title,
                                _srk_meta_description: response.meta_description,
                                _srk_canonical_url: response.canonical_url,
                                _srk_advanced_settings: response.advanced_settings,
                                _srk_last_sync: response.last_sync
                            }
                        });

                        setLastSyncTime(response.last_sync);
                        setSyncNotification(__('SEO settings updated from Classic Editor', 'seo-repair-kit'));
                        setSyncNotificationType('info');
                    }
                }
            } catch (error) {
                console.error('❌ Sync error:', error);
            }
        }, [postId, metaTitle, metaDescription, canonicalUrl, lastSyncTime, advancedSettings, editPost]);

        // Save meta function WITH SYNC - FIXED VERSION (Respects Follow Mode)
        const saveMetaData = async (includeAdvanced = true) => {
            if (!postId || isSaving) return;

            setIsSaving(true);

            try {
                // CHECK: Are we in Follow Mode?
                const isFollowMode = advancedSettings.follow_mode === '1' ||
                    (advancedSettings.use_default_settings === '1' && !metaTitle && !metaDescription);

                if (isFollowMode) {
                    console.log('🔄 FOLLOW MODE: Saving only marker, not meta values');

                    // In Follow Mode: ONLY save the follow_mode marker, NO title/desc/canonical
                    const metaUpdates = {
                        _srk_meta_title: '',  // EMPTY - delete local override
                        _srk_meta_description: '',  // EMPTY - delete local override
                        _srk_template_title: '',  // EMPTY
                        _srk_template_description: '',  // EMPTY
                        _srk_canonical_url: '',  // EMPTY
                        _srk_advanced_settings: {
                            use_default_settings: '1',
                            follow_mode: '1',
                            show_meta_box: '1'
                        },
                        _srk_last_sync: Math.floor(Date.now() / 1000)
                    };

                    // Use editPost to update meta only
                    editPost({ meta: metaUpdates });

                    // Save via AJAX - ONLY the marker, not values
                    const response = await jQuery.ajax({
                        url: srkData.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'srk_save_meta_data',
                            post_id: postId,
                            meta_title: '',  // EMPTY
                            meta_description: '',  // EMPTY
                            template_title: '',
                            template_description: '',
                            canonical_url: '',  // EMPTY
                            advanced_settings: {
                                use_default_settings: '1',
                                follow_mode: '1',
                                show_meta_box: '1'
                            },
                            follow_mode: '1',  // Flag for PHP
                            nonce: srkData.nonce
                        }
                    });

                    if (response.success) {
                        setLastSyncTime(response.data.last_sync);
                        setSyncNotification(__('Following Content Type settings (no local override)', 'seo-repair-kit'));
                        setSyncNotificationType('success');
                        setShowSavedMessage(true);
                        setTimeout(() => setShowSavedMessage(false), 3000);
                    }

                } else {
                    // NORMAL MODE: Save actual values
                    const previewData = getPreviewData();
                    const processedTitle = processTemplate(metaTitle, previewData);
                    const processedDescription = processTemplate(metaDescription, previewData);

                    const metaUpdates = {
                        _srk_meta_title: processedTitle,
                        _srk_meta_description: processedDescription,
                        _srk_template_title: metaTitle,
                        _srk_template_description: metaDescription,
                        _srk_canonical_url: canonicalUrl,
                        _srk_last_sync: Math.floor(Date.now() / 1000)
                    };

                    if (includeAdvanced) {
                        metaUpdates._srk_advanced_settings = advancedSettings;
                    }

                    editPost({ meta: metaUpdates });

                    const response = await jQuery.ajax({
                        url: srkData.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'srk_save_meta_data',
                            post_id: postId,
                            meta_title: processedTitle,
                            meta_description: processedDescription,
                            template_title: metaTitle,
                            template_description: metaDescription,
                            canonical_url: canonicalUrl,
                            advanced_settings: advancedSettings,
                            nonce: srkData.nonce
                        }
                    });

                    if (response.success) {
                        setLastSyncTime(response.data.last_sync);
                        setSyncNotification(__('SEO settings saved successfully!', 'seo-repair-kit'));
                        setSyncNotificationType('success');
                        setShowSavedMessage(true);
                        setTimeout(() => setShowSavedMessage(false), 3000);
                    }
                }

            } catch (error) {
                console.error('❌ Error saving meta:', error);
                setSyncNotification(__('Error saving SEO settings', 'seo-repair-kit'));
                setSyncNotificationType('error');
            } finally {
                setIsSaving(false);
            }
        };

        // Save advanced settings only - FIXED VERSION
        const saveAdvancedSettings = async () => {
            try {
                // Prepare meta updates
                const metaUpdates = {
                    _srk_advanced_settings: advancedSettings,
                    _srk_last_sync: Math.floor(Date.now() / 1000)
                };

                // Use editPost to update meta only
                editPost({ meta: metaUpdates });

                // Save via AJAX only
                const response = await jQuery.ajax({
                    url: srkData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'srk_save_advanced_settings',
                        post_id: postId,
                        advanced_settings: advancedSettings,
                        nonce: srkData.nonce
                    }
                });

                if (response.success) {
                    setLastSyncTime(response.data.last_sync);
                    setSyncNotification(__('Advanced settings saved!', 'seo-repair-kit'));
                    setSyncNotificationType('success');
                    setShowSavedMessage(true);
                    setTimeout(() => setShowSavedMessage(false), 3000);
                }
            } catch (error) {
                console.error('❌ Error saving advanced settings:', error);
                setSyncNotification(__('Error saving advanced settings', 'seo-repair-kit'));
                setSyncNotificationType('error');
            }
        };

        // Update the modal save button handler in Gutenberg:
        // Change this part in the modal footer:
        createElement(Button, {
            isPrimary: true,
            onClick: async () => {
                if (activeTab === 'advanced') {
                    await saveAdvancedSettings();
                } else {
                    await saveMetaData(false);
                }
                setIsModalOpen(false);
            },
            disabled: isSaving
        }, isSaving ? srkData.i18n?.saving || 'Saving...' : srkData.i18n?.saveChanges || 'Save Changes')

        // Apply content type settings
        const applyContentTypeSettings = () => {
            const contentTypeRobots = srkData.contentTypeRobots || {};
            setAdvancedSettings({
                robots_meta: contentTypeRobots,
                use_default_settings: '0'
            });
        };
        /**
         * Reset to Content Type Defaults - TRUE RESET VERSION
         * Deletes post meta and enters Follow Mode (dynamic sync)
         */
        const resetToDefaults = async () => {
            console.log('🔄 TRUE RESET: Deleting post meta and entering Follow Mode...');

            if (!confirm('Reset to Content Type defaults? This will delete all custom SEO settings for this post and follow global Content Type settings dynamically.')) {
                return;
            }

            setIsSaving(true);

            try {
                // STEP 1: Call new AJAX handler to delete meta and set Follow Mode
                const response = await jQuery.ajax({
                    url: srkData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'srk_reset_to_content_type',
                        post_id: postId,
                        nonce: srkData.nonce
                    }
                });

                if (response.success) {
                    console.log('✅ True Reset successful:', response.data);
                    // CRITICAL: Set new original values so button disables
                    setOriginalValues({
                        metaTitle: defaultTitle,
                        metaDescription: defaultDesc,
                        canonicalUrl: '',
                        advancedSettings: JSON.parse(JSON.stringify(followModeSettings))
                    });
                    setHasFormChanges(false); // This disables save button

                    // STEP 2: Update UI to show Content Type templates (not saved locally)
                    const contentTypeSettings = srkData.contentTypeSettings || {};
                    const contentTypeRobots = srkData.contentTypeRobots || {};

                    const defaultTitle = contentTypeSettings.title || srkData.defaultTitleTemplate || '%title% %sep% %site_title%';
                    const defaultDesc = contentTypeSettings.desc || srkData.defaultDescTemplate || '%excerpt%';

                    // Update form fields to show Content Type values (visual only)
                    setMetaTitle(defaultTitle);
                    setMetaDescription(defaultDesc);
                    setCanonicalUrl('');

                    // STEP 3: Set advanced settings to Follow Mode (no local robots)
                    const followModeSettings = {
                        use_default_settings: '1',
                        follow_mode: '1',  // Special flag
                        show_meta_box: '1',
                        robots_meta: contentTypeRobots  // Reference only, not saved
                    };

                    setAdvancedSettings(followModeSettings);

                    // STEP 4: Update WordPress editor meta to reflect clean state
                    // IMPORTANT: Only save _srk_advanced_settings with follow_mode flag
                    // DO NOT save _srk_meta_title or _srk_meta_description
                    editPost({
                        meta: {
                            _srk_meta_title: '',  // Empty = deleted
                            _srk_meta_description: '',  // Empty = deleted  
                            _srk_canonical_url: '',  // Empty = deleted
                            _srk_template_title: '',  // Empty = deleted
                            _srk_template_description: '',  // Empty = deleted
                            _srk_advanced_settings: followModeSettings,  // Only this survives
                            _srk_last_sync: Math.floor(Date.now() / 1000)
                        }
                    });

                    // Update hidden fields
                    $('#srk_meta_title').val('');
                    $('#srk_meta_description').val('');
                    $('#srk_canonical_url').val('');
                    $('#srk_advanced_settings').val(JSON.stringify(followModeSettings));

                    setLastSyncTime(response.data.last_sync);
                    setSyncNotification(__('Reset successful - Now following Content Type dynamically', 'seo-repair-kit'));
                    setSyncNotificationType('success');
                    setShowSavedMessage(true);
                    setTimeout(() => setShowSavedMessage(false), 3000);

                    console.log('✅ Post is now in FOLLOW MODE - No local meta saved');
                } else {
                    console.error('❌ Reset failed:', response);
                    setSyncNotification(__('Reset failed', 'seo-repair-kit'));
                    setSyncNotificationType('error');
                }

            } catch (error) {
                console.error('❌ Error during reset:', error);
                setSyncNotification(__('Error during reset', 'seo-repair-kit'));
                setSyncNotificationType('error');
            } finally {
                setIsSaving(false);
            }
        };

        // Prepare preview data
        const previewData = {
            postTitle: postTitle || 'Sample Post Title',
            postExcerpt: postExcerpt || 'Sample excerpt from a page/post.',
            pageTitle: 'Sample Page Title',
            authorFirstName: srkData.authorFirstName,
            authorLastName: srkData.authorLastName,
            authorName: srkData.authorName,
            categories: srkData.categories,
            categoryTitle: srkData.categoryTitle,
            currentDate: srkData.currentDate,
            currentDay: srkData.currentDay,
            currentMonth: srkData.currentMonth,
            currentYear: srkData.currentYear,
            customField: srkData.customField,
            permalink: srkData.permalink,
            postContent: srkData.postContent,
            postDate: srkData.postDate,
            postDay: srkData.postDay
        };

        // Get display values
        const displayTitle = metaTitle || defaultTitleTemplate;
        const displayDesc = metaDescription || defaultDescTemplate;

        // Process templates for preview
        const titlePreview = processTemplate(displayTitle, previewData);
        const descPreview = processTemplate(displayDesc, previewData);

        // Tab panel configuration
        const tabConfig = [
            {
                name: 'title-description',
                title: srkData.i18n?.titleDescription || 'Title & Description',
                className: 'srk-tab-title-description',
                content: createElement('div', { style: { padding: '10px 0' } },
                    createElement(VisualTagInput, {
                        value: metaTitle,
                        onChange: setMetaTitle,
                        placeholder: processTemplate(defaultTitleTemplate, previewData),
                        type: 'title',
                        label: srkData.i18n?.seoTitle || 'SEO Title',
                        showDefaults: true,
                        onResetToDefault: () => setMetaTitle(defaultTitleTemplate)
                    }),

                    createElement(VisualTagInput, {
                        value: metaDescription,
                        onChange: setMetaDescription,
                        placeholder: processTemplate(defaultDescTemplate, previewData),
                        type: 'description',
                        label: srkData.i18n?.metaDescription || 'Meta Description',
                        showDefaults: true,
                        onResetToDefault: () => setMetaDescription(defaultDescTemplate)
                    }),

                    createElement(BaseControl, {
                        label: srkData.i18n?.canonicalUrl || 'Canonical URL',
                        help: srkData.i18n?.canonicalHelp || 'Preferred URL for this content',
                        className: 'srk-field-control',
                        style: { marginTop: '20px' }
                    },
                        createElement(TextControl, {
                            value: canonicalUrl,
                            onChange: setCanonicalUrl,
                            placeholder: (srkData.siteUrl || '') + '/your-page/',
                            style: { width: '100%' }
                        })
                    )
                )
            },
            {
                name: 'advanced',
                title: srkData.i18n?.advanced || 'Advanced',
                className: 'srk-tab-advanced',
                content: createElement(AdvancedSettings, {
                    settings: advancedSettings,
                    onChange: setAdvancedSettings,
                    onApplyContentTypeSettings: applyContentTypeSettings
                })
            }
        ];

        return createElement(
            'div',
            null,
            createElement(SyncNotification, {
                message: syncNotification,
                type: syncNotificationType
            }),

            showSavedMessage && createElement(Snackbar, {
                className: 'srk-saved-message',
                onRemove: () => setShowSavedMessage(false)
            },
                __('SEO settings saved successfully!', 'seo-repair-kit')
            ),

            createElement(
                PluginDocumentSettingPanel,
                {
                    name: 'srk-seo-panel',
                    title: __('SEO Repair Kit', 'seo-repair-kit'),
                    className: 'srk-seo-panel',
                    icon: 'search'
                },
                // Preview Section
                createElement('div', { style: { padding: '16px' } },
                    createElement('div', {
                        style: {
                            marginBottom: '20px',
                            padding: '12px',
                            background: '#f9f9f9',
                            border: '1px solid #e0e0e0',
                            borderRadius: '4px'
                        }
                    },
                        createElement('div', {
                            style: {
                                display: 'flex',
                                justifyContent: 'space-between',
                                alignItems: 'center',
                                marginBottom: '10px'
                            }
                        },
                            createElement('strong', null, srkData.i18n?.preview || 'Preview:'),
                            createElement('div', { style: { display: 'flex', alignItems: 'center', gap: '10px' } },
                                createElement(Button, {
                                    isSecondary: true,
                                    onClick: () => setIsModalOpen(true),
                                    style: {
                                        height: '30px',
                                        fontSize: '12px',
                                        padding: '0 10px'
                                    }
                                }, srkData.i18n?.editSnippet || 'Edit Snippet')
                            )
                        ),
                        createElement('div', { style: { color: '#70757a', fontSize: '12px', marginBottom: '8px', fontFamily: 'Arial, sans-serif' } },
                            srkData.siteUrl || 'example.com'
                        ),
                        createElement('div', { style: { color: '#1a0dab', fontSize: '16px', marginBottom: '8px', fontFamily: 'Arial, sans-serif', fontWeight: '400', lineHeight: '1.4' } },
                            titlePreview || srkData.i18n?.noTitle || '(No title)'
                        ),
                        createElement('div', { style: { color: '#3c4043', fontSize: '14px', fontFamily: 'Arial, sans-serif', lineHeight: '1.5' } },
                            descPreview || srkData.i18n?.noDescription || '(No description)'
                        )
                    )
                )
            ),

            // Modal for editing snippet with tabs
            isModalOpen && createElement(Modal, {
                title: __('Edit SEO Snippet', 'seo-repair-kit'),
                onRequestClose: () => {
                    setIsModalOpen(false);
                },
                className: 'srk-seo-modal',
                style: { maxWidth: '700px', height: '80vh' }
            },
                createElement('div', { style: { display: 'flex', flexDirection: 'column', height: '100%' } },
                    // Tabs Navigation
                    createElement('div', {
                        style: {
                            display: 'flex',
                            borderBottom: '1px solid #ddd',
                            background: '#f9f9f9'
                        }
                    },
                        tabConfig.map((tab) =>
                            createElement('button', {
                                key: tab.name,
                                className: `srk-modal-tab ${activeTab === tab.name ? 'active' : ''}`,
                                onClick: () => setActiveTab(tab.name),
                                style: {
                                    padding: '12px 20px',
                                    background: 'none',
                                    border: 'none',
                                    borderBottom: activeTab === tab.name ? '2px solid #007cba' : '2px solid transparent',
                                    fontSize: '14px',
                                    fontWeight: activeTab === tab.name ? '600' : '400',
                                    color: activeTab === tab.name ? '#007cba' : '#646970',
                                    cursor: 'pointer',
                                    transition: 'all 0.2s'
                                }
                            }, tab.title)
                        )
                    ),

                    // Tab Content
                    createElement('div', {
                        style: {
                            flex: 1,
                            overflowY: 'auto',
                            padding: '20px'
                        }
                    },
                        activeTab === 'title-description' && createElement('div', null,
                            // SEO Title with Visual Tag Input
                            createElement(VisualTagInput, {
                                value: metaTitle,
                                onChange: setMetaTitle,
                                placeholder: processTemplate(defaultTitleTemplate, previewData),
                                type: 'title',
                                label: srkData.i18n?.seoTitle || 'SEO Title',
                                showDefaults: true,
                                onResetToDefault: () => setMetaTitle(defaultTitleTemplate)
                            }),

                            // Meta Description with Visual Tag Input
                            createElement(VisualTagInput, {
                                value: metaDescription,
                                onChange: setMetaDescription,
                                placeholder: processTemplate(defaultDescTemplate, previewData),
                                type: 'description',
                                label: srkData.i18n?.metaDescription || 'Meta Description',
                                showDefaults: true,
                                onResetToDefault: () => setMetaDescription(defaultDescTemplate)
                            }),

                            // Canonical URL
                            createElement(BaseControl, {
                                label: srkData.i18n?.canonicalUrl || 'Canonical URL',
                                help: srkData.i18n?.canonicalHelp || 'Preferred URL for this content',
                                className: 'srk-field-control',
                                style: { marginTop: '20px' }
                            },
                                createElement(TextControl, {
                                    value: canonicalUrl,
                                    onChange: setCanonicalUrl,
                                    placeholder: (srkData.siteUrl || '') + '/your-page/',
                                    style: { width: '100%' }
                                })
                            ),

                            // Live Preview
                            createElement('div', {
                                style: {
                                    marginTop: '30px',
                                    padding: '15px',
                                    background: '#f9f9f9',
                                    border: '1px solid #e0e0e0',
                                    borderRadius: '4px'
                                }
                            },
                                createElement('strong', { style: { display: 'block', marginBottom: '10px' } },
                                    srkData.i18n?.preview || 'Live Preview:'
                                ),
                                createElement('div', { style: { color: '#70757a', fontSize: '12px', marginBottom: '8px', fontFamily: 'Arial, sans-serif' } },
                                    srkData.siteUrl || 'example.com'
                                ),
                                createElement('div', { style: { color: '#1a0dab', fontSize: '16px', marginBottom: '8px', fontFamily: 'Arial, sans-serif', fontWeight: '400', lineHeight: '1.4' } },
                                    titlePreview || srkData.i18n?.noTitle || '(No title)'
                                ),
                                createElement('div', { style: { color: '#3c4043', fontSize: '14px', fontFamily: 'Arial, sans-serif', lineHeight: '1.5' } },
                                    descPreview || srkData.i18n?.noDescription || '(No description)'
                                )
                            )
                        ),

                        activeTab === 'advanced' && createElement(AdvancedSettings, {
                            settings: advancedSettings,
                            onChange: setAdvancedSettings,
                            onApplyContentTypeSettings: applyContentTypeSettings
                        })
                    ),

                    // Action buttons
                    createElement('div', {
                        style: {
                            padding: '20px',
                            borderTop: '1px solid #ddd',
                            background: '#f9f9f9'
                        }
                    },
                        createElement('div', {
                            style: {
                                display: 'flex',
                                justifyContent: 'space-between',
                                alignItems: 'center'
                            }
                        },
                            createElement('div', null,
                                activeTab === 'title-description' && createElement(Button, {
                                    isSecondary: true,
                                    onClick: resetToDefaults,
                                    style: { marginRight: '10px' }
                                }, srkData.i18n?.resetAll || 'Reset to Defaults')
                            ),
                            createElement('div', { style: { display: 'flex', alignItems: 'center' } },
                                isSaving && createElement(Spinner, { style: { marginRight: '10px' } }),
                                createElement(Button, {
                                    isSecondary: true,
                                    onClick: () => setIsModalOpen(false),
                                    style: { marginRight: '10px' }
                                }, srkData.i18n?.cancel || 'Cancel'),
                                createElement(Button, {
                                    isPrimary: true,
                                    onClick: async () => {
                                        if (activeTab === 'advanced') {
                                            await saveAdvancedSettings();
                                        } else {
                                            await saveMetaData(false);
                                        }
                                        setIsModalOpen(false);
                                    },
                                    disabled: isSaving || !hasFormChanges
                                }, isSaving ? srkData.i18n?.saving || 'Saving...' : srkData.i18n?.saveChanges || 'Save Changes')
                            )
                        )
                    )
                )
            )
        );
    };

    // Register the Gutenberg plugin
    console.log('📝 Registering Gutenberg SEO Panel plugin with tabs...');

    try {
        registerPlugin('srk-gutenberg-seo-panel', {
            render: SRKGutenbergSeoPanel,
            icon: 'search'
        });

        console.log('✅ Gutenberg SEO Panel registered successfully with tabs!');
    } catch (error) {
        console.error('❌ Error registering Gutenberg plugin:', error);
    }

})(window.wp);

console.log('✅ Gutenberg editor integration loaded with tabs');

// ============================================================================
// PART 2: CLASSIC EDITOR COMPONENTS
// ============================================================================
// ============================================================================
// PART 2: CLASSIC EDITOR COMPONENTS WITH ADVANCED TABS
// ============================================================================
(function ($) {

    'use strict';

    console.log('🔧 Initializing Classic Editor Integration with Tabs...');

    // Global data
    const srkData = window.srkGutenbergData || {};
    let currentEditingTag = null;
    let currentEditingTagIndex = -1;
    let currentEditingTarget = null;
    let currentPostData = null;
    let lastSyncTime = 0;
    let syncInterval;
    let isSaving = false;
    let saveTimeout = null;
    let isDirty = false;
    let originalValuesClassic = {
        title: '',
        description: '',
        canonical: '',
        advanced: {}
    };
    let hasFormChangesClassic = false;
    let advancedSettings = {
        robots_meta: {
            noindex: '0',
            nofollow: '0',
            noarchive: '0',
            notranslate: '0',
            noimageindex: '0',
            nosnippet: '0',
            noodp: '0',
            max_snippet: -1,
            max_video_preview: -1,
            max_image_preview: 'large'
        },
        use_default_settings: '1'
    };
    let activeTab = 'title-description';

    console.log('📦 SRK Data Available for Classic Editor:', srkData);

    // Initialize when document is ready
    $(document).ready(function () {
        console.log('🎯 Classic Editor Initializing...');

        if (!$('#srk-metabox-container').length) {
            console.error('❌ SRK Metabox container not found!');
            return;
        }

        console.log('✅ SRK Metabox container found');

        initializeClassicUI();
        loadPostData();
        startSync();

        // Add beforeunload handler to save on page unload
        $(window).on('beforeunload', function (e) {
            if (isDirty) {
                const confirmationMessage = 'You have unsaved SEO changes. Are you sure you want to leave?';
                (e || window.event).returnValue = confirmationMessage;
                return confirmationMessage;
            }
        });

        // Save on window unload
        $(window).on('unload', function () {
            saveBeforeUnload();
        });

        // Also save when WordPress save button is clicked
        $(document).on('click', '#publish, #save-post', function () {
            console.log('💾 WordPress save button clicked, saving SEO data...');
            saveMetaData(true);
        });
    });


    /**
     * Save before page unload
     */
    function saveBeforeUnload() {
        if (!isDirty || isSaving) return;

        console.log('🔄 Saving before page unload...');
        clearTimeout(saveTimeout);
        saveMetaData(true);
    }
    function checkFormChanges() {
        const currentTitle = $('.srk-value-input[data-type="title"]').val();
        const currentDesc = $('.srk-value-input[data-type="description"]').val();
        const currentCanonical = $('#srk-canonical-input').val();

        hasFormChangesClassic =
            currentTitle !== originalValuesClassic.title ||
            currentDesc !== originalValuesClassic.description ||
            currentCanonical !== originalValuesClassic.canonical ||
            JSON.stringify(advancedSettings) !== JSON.stringify(originalValuesClassic.advanced);

        // Update button
        $('#srk-save').prop('disabled', !hasFormChangesClassic);
        $('#srk-save').css('opacity', hasFormChangesClassic ? '1' : '0.5');
    }

    /**
     * Check if there are unsaved changes
     */
    function hasChanges() {
        const currentTitle = $('.srk-value-input[data-type="title"]').val();
        const currentDesc = $('.srk-value-input[data-type="description"]').val();
        const currentCanonical = $('#srk-canonical-input').val();

        const savedTitle = $('#srk_meta_title').val();
        const savedDesc = $('#srk_meta_description').val();
        const savedCanonical = $('#srk_canonical_url').val();

        const defaultTitle = srkData.defaultTitleTemplate || '%title% %sep% %site_title%';
        const defaultDesc = srkData.defaultDescTemplate || '%excerpt%';

        const titleChanged = currentTitle !== savedTitle && currentTitle !== defaultTitle;
        const descChanged = currentDesc !== savedDesc && currentDesc !== defaultDesc;
        const canonicalChanged = currentCanonical !== savedCanonical;

        return titleChanged || descChanged || canonicalChanged;
    }

    /**
     * Mark form as dirty
     */
    function markAsDirty() {
        if (!isDirty) {
            isDirty = true;
            console.log('📝 Form marked as dirty');
        }
    }

    /**
     * Mark form as clean
     */
    function markAsClean() {
        isDirty = false;
        console.log('✅ Form marked as clean');
    }

    /**
     * Start real-time sync
     */
    function startSync() {
        syncInterval = setInterval(checkForUpdates, srkData.syncInterval || 5000);
        $(window).on('focus', checkForUpdates);
        console.log('🔄 Real-time sync started');
    }

    /**
     * Check for updates from Gutenberg
     */
    function checkForUpdates() {
        if (!srkData.postId || isSaving) return;

        $.ajax({
            url: srkData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'srk_sync_meta_data',
                post_id: srkData.postId,
                nonce: srkData.nonce
            },
            success: function (response) {
                if (response.success) {
                    const data = response.data;

                    if (data.last_sync > lastSyncTime) {
                        updateFromSync(data);
                        lastSyncTime = data.last_sync;
                        showSyncNotification('Settings updated from Gutenberg');
                    }
                }
            },
            error: function (xhr, status, error) {
                console.error('❌ Sync error:', error);
            }
        });
    }

    /**
     * Update UI from sync data
     */
    function updateFromSync(data) {
        console.log('🔄 Updating from sync:', data);

        const defaultTitle = srkData.defaultTitleTemplate || '%title% %sep% %site_title%';
        const defaultDesc = srkData.defaultDescTemplate || '%excerpt%';

        const currentTitle = $('.srk-value-input[data-type="title"]').val() || defaultTitle;
        const currentDesc = $('.srk-value-input[data-type="description"]').val() || defaultDesc;
        const currentCanonical = $('#srk-canonical-input').val() || '';

        if (data.meta_title !== currentTitle) {
            const newTitle = data.meta_title || defaultTitle;
            $('.srk-value-input[data-type="title"]').val(newTitle);
            $('#srk_meta_title').val(newTitle);
            renderDisplayFromValue(newTitle, 'title');
        }

        if (data.meta_description !== currentDesc) {
            const newDesc = data.meta_description || defaultDesc;
            $('.srk-value-input[data-type="description"]').val(newDesc);
            $('#srk_meta_description').val(newDesc);
            renderDisplayFromValue(newDesc, 'description');
        }

        if (data.canonical_url !== currentCanonical) {
            $('#srk-canonical-input').val(data.canonical_url || '');
            $('#srk_canonical_url').val(data.canonical_url || '');
        }

        // Update advanced settings
        if (data.advanced_settings && JSON.stringify(data.advanced_settings) !== JSON.stringify(advancedSettings)) {
            advancedSettings = data.advanced_settings;
            updateAdvancedSettingsUI();
        }

        lastSyncTime = data.last_sync;
        updatePreview();

        showSyncNotification('Settings updated from Gutenberg/Block Editor');
        markAsClean();
    }

    /**
     * Update advanced settings UI
     */
    /**
 * Update advanced settings UI - WITHOUT CHECKBOX
 */
    function updateAdvancedSettingsUI() {
        console.log('🔄 Updating advanced settings UI:', advancedSettings);

        // Update toggle switch visual state (NO CHECKBOX)
        const isUsingDefaults = advancedSettings.use_default_settings === '1';
        const $toggle = $('#srk-toggle-wrapper');
        const $knob = $('#srk-toggle-knob');

        if (isUsingDefaults) {
            $toggle.addClass('active').css('background', '#007cba');
            $knob.css('left', '30px');
            $('.srk-robots-settings-container').hide();
        } else {
            $toggle.removeClass('active').css('background', '#ccc');
            $knob.css('left', '4px');
            $('.srk-robots-settings-container').show();
        }

        // Update robots meta checkboxes
        if (advancedSettings.robots_meta) {
            $('#srk-robots-noindex').prop('checked', advancedSettings.robots_meta.noindex === '1');
            $('#srk-robots-nofollow').prop('checked', advancedSettings.robots_meta.nofollow === '1');
            $('#srk-robots-noarchive').prop('checked', advancedSettings.robots_meta.noarchive === '1');
            $('#srk-robots-notranslate').prop('checked', advancedSettings.robots_meta.notranslate === '1');
            $('#srk-robots-noimageindex').prop('checked', advancedSettings.robots_meta.noimageindex === '1');
            $('#srk-robots-nosnippet').prop('checked', advancedSettings.robots_meta.nosnippet === '1');
            $('#srk-robots-noodp').prop('checked', advancedSettings.robots_meta.noodp === '1');
            $('#srk-max-snippet').val(advancedSettings.robots_meta.max_snippet);
            $('#srk-max-video-preview').val(advancedSettings.robots_meta.max_video_preview);
            $('#srk-max-image-preview').val(advancedSettings.robots_meta.max_image_preview);
        }
    }

    /**
     * Show sync notification
     */
    function showSyncNotification(message, isError = false) {
        const $status = $('#srk-sync-status');
        $status.find('.srk-sync-text').text(message);

        if (isError) {
            $status.addClass('srk-sync-error');
        } else {
            $status.addClass('srk-sync-success');
        }

        $status.fadeIn();

        setTimeout(function () {
            $status.fadeOut(300, function () {
                $status.removeClass('srk-sync-success srk-sync-error');
            });
        }, 3000);
    }

    /**
     * Load post data via AJAX
     */
    function loadPostData() {
        console.log('📡 Loading post data...');

        if (!srkData.postId) {
            console.error('❌ No post ID available');
            useSampleData();
            return;
        }

        // Get initial values from hidden fields
        const initialTitle = $('#srk_meta_title').val();
        const initialDesc = $('#srk_meta_description').val();
        const initialCanonical = $('#srk_canonical_url').val();
        const initialAdvancedSettings = $('#srk_advanced_settings').val();

        console.log('📝 Initial hidden values:', {
            title: initialTitle,
            desc: initialDesc,
            canonical: initialCanonical,
            advanced: initialAdvancedSettings
        });

        // Update UI with initial values immediately
        if (initialTitle) {
            $(`.srk-value-input[data-type="title"]`).val(initialTitle);
            renderDisplayFromValue(initialTitle, 'title');
        }

        if (initialDesc) {
            $(`.srk-value-input[data-type="description"]`).val(initialDesc);
            renderDisplayFromValue(initialDesc, 'description');
        }

        if (initialCanonical) {
            $('#srk-canonical-input').val(initialCanonical);
        }

        if (initialAdvancedSettings) {
            try {
                advancedSettings = JSON.parse(initialAdvancedSettings);
                updateAdvancedSettingsUI();
            } catch (e) {
                console.error('❌ Error parsing advanced settings:', e);
            }
        }

        $.ajax({
            url: srkData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'srk_get_post_data',
                post_id: srkData.postId,
                nonce: srkData.nonce
            },
            success: function (response) {
                console.log('📡 Post data response:', response);

                if (response.success) {
                    currentPostData = response.data;
                    lastSyncTime = parseInt(response.data.last_sync) || 0;
                    console.log('✅ Post data loaded, last sync:', lastSyncTime);

                    if (response.data.meta_title && response.data.meta_title !== '') {
                        $(`.srk-value-input[data-type="title"]`).val(response.data.meta_title);
                        $(`#srk_meta_title`).val(response.data.meta_title);
                        renderDisplayFromValue(response.data.meta_title, 'title');
                    }

                    if (response.data.meta_description && response.data.meta_description !== '') {
                        $(`.srk-value-input[data-type="description"]`).val(response.data.meta_description);
                        $(`#srk_meta_description`).val(response.data.meta_description);
                        renderDisplayFromValue(response.data.meta_description, 'description');
                    }

                    if (response.data.canonical_url && response.data.canonical_url !== '') {
                        $('#srk-canonical-input').val(response.data.canonical_url);
                        $('#srk_canonical_url').val(response.data.canonical_url);
                    }

                    if (response.data.advanced_settings) {
                        advancedSettings = response.data.advanced_settings;
                        updateAdvancedSettingsUI();
                    }

                    updateUIWithPostData();
                } else {
                    console.error('❌ Error loading post data');
                    useSampleData();
                }
            },
            error: function (xhr, status, error) {
                console.error('❌ AJAX error:', error);
                useSampleData();
            }
        });
    }

    /**
     * Use sample data if AJAX fails
     */
    function useSampleData() {
        console.log('📝 Using sample data...');

        currentPostData = {
            post_title: 'Sample Post Title',
            post_excerpt: 'Sample excerpt from a page/post.',
            post_content: 'Sample post content text...',
            permalink: (srkData.siteUrl || 'https://example.com') + '/sample-post/',
            post_date: new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }),
            post_day: new Date().getDate().toString(),
            last_sync: 0
        };

        updateUIWithPostData();
    }

    /**
     * Initialize the Classic Editor UI with Tabs
     */
    function initializeClassicUI() {
        console.log('🎨 Initializing Classic Editor UI with Tabs...');

        const metaTitle = $('#srk_meta_title').val();
        const metaDescription = $('#srk_meta_description').val();
        const canonicalUrl = $('#srk_canonical_url').val();

        console.log('📝 Initial Values:', { metaTitle, metaDescription, canonicalUrl });

        // Create UI content with Tabs
        const uiContent = `
            <div class="srk-meta-box-content">
                <!-- Tabs Navigation - Simple bottom border style -->
                <div class="srk-classic-tabs" style="display: flex; border-bottom: 2px solid #e0e0e0; margin-bottom: 25px;">
                    <button type="button" class="srk-classic-tab active" data-tab="title-description"
                            style="padding: 12px 20px; background: none; border: none; font-size: 14px; font-weight: 600; color: #007cba; cursor: pointer; position: relative; margin-bottom: -2px;">
                        ${srkData.i18n?.titleDescription || 'Title & Description'}
                        <span class="tab-indicator" style="position: absolute; bottom: -2px; left: 0; right: 0; height: 2px; background: #007cba; display: block;"></span>
                    </button>
                    <button type="button" class="srk-classic-tab" data-tab="advanced"
                            style="padding: 12px 20px; background: none; border: none; font-size: 14px; font-weight: 500; color: #646970; cursor: pointer; position: relative; margin-bottom: -2px;">
                        ${srkData.i18n?.advanced || 'Advanced'}
                        <span class="tab-indicator" style="position: absolute; bottom: -2px; left: 0; right: 0; height: 2px; background: transparent; display: block;"></span>
                    </button>
                </div>
           
                <!-- Preview Box (Always Visible) -->
                <div class="srk-preview-box" style="margin: 20px 0; padding: 15px; background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 4px;">
                   
                    <div id="srk-preview-url" style="color: #70757a; font-size: 12px; margin-bottom: 8px; font-family: Arial, sans-serif;">
                        ${srkData.siteUrl ? srkData.siteUrl.replace(/^https?:\/\//, '') : 'example.com'}
                    </div>
                    <div id="srk-preview-title" style="color: #1a0dab; font-size: 16px; margin-bottom: 8px; font-family: Arial, sans-serif; font-weight: 400; line-height: 1.4;">
                        ${metaTitle ? processTemplateClassic(metaTitle, getPreviewData()) : processTemplateClassic(srkData.defaultTitleTemplate || '%title% %sep% %site_title%', getPreviewData())}
                    </div>
                    <div id="srk-preview-description" style="color: #3c4043; font-size: 14px; font-family: Arial, sans-serif; line-height: 1.5;">
                        ${metaDescription ? processTemplateClassic(metaDescription, getPreviewData()) : processTemplateClassic(srkData.defaultDescTemplate || '%excerpt%', getPreviewData())}
                    </div>
                </div>
           
                <!-- Tab Contents Container -->
                <div class="srk-tabs-content-container">
                    <!-- Title & Description Tab Content -->
                    <div class="srk-tab-content active" data-tab="title-description">
                        <!-- SEO Title with Visual Tag Input -->
                        <div id="srk-title-section" style="margin-bottom: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <label style="font-weight: 600; font-size: 13px; color: #1d2327;">
                                    ${srkData.i18n?.seoTitle || 'SEO Title'}
                                </label>
                                <button type="button" class="srk-reset-btn" data-type="title" style="font-size: 12px; height: 24px; padding: 0 8px; background: none; border: 1px solid #ddd; border-radius: 3px; cursor: pointer;">
                                    ${srkData.i18n?.reset || 'Reset to Default'}
                                </button>
                            </div>
                       
                            <div id="srk-title-tags" style="margin-bottom: 8px; display: flex; flex-wrap: wrap; align-items: center; gap: 4px;">
                                <!-- Relevant tags loaded here -->
                            </div>
                       
                            <div class="srk-visual-input" data-type="title" style="position: relative; min-height: 40px;">
                                <div class="srk-tag-display"
                                    data-type="title"
                                    contenteditable="true"
                                    style="min-height: 40px; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; background: #fff; cursor: text; line-height: 1.5; white-space: pre-wrap; outline: none;"
                                    placeholder="${processTemplateClassic(srkData.defaultTitleTemplate || '%title% %sep% %site_title%', getPreviewData())}">
                                    ${metaTitle ? '' : processTemplateClassic(srkData.defaultTitleTemplate || '%title% %sep% %site_title%', getPreviewData())}
                                </div>
                                <input type="hidden" class="srk-value-input" data-type="title" value="${metaTitle || ''}">
                            </div>
                            <p style="margin: 4px 0 0 0; font-size: 12px; color: #646970;">
                                ${srkData.i18n?.titleHelp || 'Title shown in search results'}
                            </p>
                        </div>

                        <!-- Meta Description with Visual Tag Input -->
                        <div id="srk-description-section" style="margin-bottom: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <label style="font-weight: 600; font-size: 13px; color: #1d2327;">
                                    ${srkData.i18n?.metaDescription || 'Meta Description'}
                                </label>
                                <button type="button" class="srk-reset-btn" data-type="description" style="font-size: 12px; height: 24px; padding: 0 8px; background: none; border: 1px solid #ddd; border-radius: 3px; cursor: pointer;">
                                    ${srkData.i18n?.reset || 'Reset to Default'}
                                </button>
                            </div>
                       
                            <div id="srk-description-tags" style="margin-bottom: 8px; display: flex; flex-wrap: wrap; align-items: center; gap: 4px;">
                                <!-- Relevant tags loaded here -->
                            </div>
                       
                            <div class="srk-visual-input" data-type="description" style="position: relative; min-height: 60px;">
                                <div class="srk-tag-display"
                                    data-type="description"
                                    contenteditable="true"
                                    style="min-height: 60px; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; background: #fff; cursor: text; line-height: 1.5; white-space: pre-wrap; outline: none;"
                                    placeholder="${processTemplateClassic(srkData.defaultDescTemplate || '%excerpt%', getPreviewData())}">
                                    ${metaDescription ? '' : processTemplateClassic(srkData.defaultDescTemplate || '%excerpt%', getPreviewData())}
                                </div>
                                <input type="hidden" class="srk-value-input" data-type="description" value="${metaDescription || ''}">
                            </div>
                            <p style="margin: 4px 0 0 0; font-size: 12px; color: #646970;">
                                ${srkData.i18n?.descHelp || 'Description shown in search results'}
                            </p>
                        </div>

                        <!-- Canonical URL -->
                        <div style="margin-bottom: 20px;">
                            <label style="font-weight: 600; font-size: 13px; color: #1d2327; display: block; margin-bottom: 8px;">
                                ${srkData.i18n?.canonicalUrl || 'Canonical URL'}
                            </label>
                            <input type="text" id="srk-canonical-input" value="${canonicalUrl || ''}" placeholder="${srkData.siteUrl || 'https://example.com'}/your-page/" style="width: 100%; padding: 8px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 14px;">
                            <p style="margin: 4px 0 0 0; font-size: 12px; color: #646970;">
                                ${srkData.i18n?.canonicalHelp || 'Preferred URL for this content'}
                            </p>
                        </div>
                    </div>
               
                    <!-- Advanced Tab Content - WITH CONDITIONAL FIELDS -->
                    <div class="srk-tab-content" data-tab="advanced" style="display: none;">
                        <div class="srk-advanced-section">
                            <!-- Use Default Settings Toggle - WITHOUT CHECKBOX -->
                            <div style="margin-bottom: 20px;">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px;">
                                    <!-- Custom Toggle Switch (No checkbox, pure CSS/JS controlled) -->
                                    <div id="srk-toggle-wrapper" 
                                        class="srk-toggle-switch ${advancedSettings.use_default_settings === '1' ? 'active' : ''}" 
                                        style="position: relative; display: inline-block; width: 50px; height: 24px; background: ${advancedSettings.use_default_settings === '1' ? '#007cba' : '#ccc'}; border-radius: 24px; cursor: pointer; transition: background 0.3s;">
                                        <span id="srk-toggle-knob" 
                                            style="position: absolute; height: 16px; width: 16px; left: ${advancedSettings.use_default_settings === '1' ? '30px' : '4px'}; bottom: 4px; background-color: white; border-radius: 50%; transition: left 0.3s;">
                                        </span>
                                    </div>
                                    <span style="font-size: 13px; color: #1d2327; font-weight: 500;">
                                        ${srkData.i18n?.useDefaultSettings || 'Use Default Settings'}
                                    </span>
                                </div>
                            </div>
                    
                            <!-- Robots Meta Settings -->
                            <div class="srk-robots-settings-container" style="${advancedSettings.use_default_settings === '1' ? 'display: none;' : ''}">
                                <h4 style="margin-bottom: 15px; color: #1d2327;">
                                    ${srkData.i18n?.robotsMeta || 'Robots Meta Settings'}
                                </h4>
                        
                                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 20px;">
                                    <label style="display: flex; align-items: center; gap: 8px;">
                                        <input type="checkbox" id="srk-robots-noindex">
                                        <span>${srkData.i18n?.noIndex || 'No Index'}</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 8px;">
                                        <input type="checkbox" id="srk-robots-nofollow">
                                        <span>${srkData.i18n?.noFollow || 'No Follow'}</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 8px;">
                                        <input type="checkbox" id="srk-robots-noarchive">
                                        <span>${srkData.i18n?.noArchive || 'No Archive'}</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 8px;">
                                        <input type="checkbox" id="srk-robots-notranslate">
                                        <span>${srkData.i18n?.noTranslate || 'No Translate'}</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 8px;">
                                        <input type="checkbox" id="srk-robots-noimageindex">
                                        <span>${srkData.i18n?.noImageIndex || 'No Image Index'}</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 8px;">
                                        <input type="checkbox" id="srk-robots-nosnippet">
                                        <span>${srkData.i18n?.noSnippet || 'No Snippet'}</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 8px;">
                                        <input type="checkbox" id="srk-robots-noodp">
                                        <span>${srkData.i18n?.noOdp || 'No ODP'}</span>
                                    </label>
                                </div>
                        
                                <!-- Max Values - WITH CONDITIONAL DISPLAY -->
                                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-top: 20px;">
                                    <!-- MAX SNIPPET - Will be hidden when No Snippet is checked -->
                                    <div id="srk-max-snippet-wrapper" style="display: block;">
                                        <label for="srk-max-snippet" style="display: block; margin-bottom: 5px; font-size: 12px; font-weight: 600; color: #1d2327;">
                                            ${srkData.i18n?.maxSnippet || 'Max Snippet'}
                                        </label>
                                        <input type="number" id="srk-max-snippet" class="small-text" min="-1" step="1" style="width: 100%;">
                                    </div>
                                
                                    <!-- MAX VIDEO PREVIEW - Always visible -->
                                    <div>
                                        <label for="srk-max-video-preview" style="display: block; margin-bottom: 5px; font-size: 12px; font-weight: 600; color: #1d2327;">
                                            ${srkData.i18n?.maxVideoPreview || 'Max Video Preview'}
                                        </label>
                                        <input type="number" id="srk-max-video-preview" class="small-text" min="-1" step="1" style="width: 100%;">
                                    </div>
                                
                                    <!-- MAX IMAGE PREVIEW - Will be hidden when No Image Index is checked -->
                                    <div id="srk-max-image-wrapper" style="display: block;">
                                        <label for="srk-max-image-preview" style="display: block; margin-bottom: 5px; font-size: 12px; font-weight: 600; color: #1d2327;">
                                            ${srkData.i18n?.maxImagePreview || 'Max Image Preview'}
                                        </label>
                                        <select id="srk-max-image-preview" style="width: 100%;">
                                            <option value="none">${srkData.i18n?.none || 'None'}</option>
                                            <option value="standard">${srkData.i18n?.standard || 'Standard'}</option>
                                            <option value="large">${srkData.i18n?.large || 'Large'}</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    </div>
                </div>
           
                <!-- Save Status -->
                <div id="srk-save-status" style="margin-top: 20px; padding: 10px; border: 1px solid #dcdcde; border-radius: 4px; background: #f0f6fc; display: none;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span id="srk-save-status-icon" style="font-size: 16px;">⏳</span>
                        <span id="srk-save-status-text" style="font-size: 13px; color: #1d2327;">
                            ${srkData.i18n?.saving || 'Saving changes...'}
                        </span>
                    </div>
                </div>

                <!-- Save Button -->
                <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #dcdcde; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <button type="button" id="srk-reset-all" class="button button-secondary" style="font-size: 13px; padding: 0 12px;">
                            ${srkData.i18n?.resetAll || 'Reset All to Defaults'}
                        </button>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span id="srk-saving-text" style="display: none; font-size: 12px; color: #666;">${srkData.i18n?.saving || 'Saving...'}</span>
                        <span id="srk-saved-text" style="display: none; font-size: 12px; color: #00a32a;">${srkData.i18n?.saved || 'Saved!'}</span>
                        <button type="button" id="srk-save" class="button button-primary" style="font-size: 13px; padding: 0 12px;">
                            ${srkData.i18n?.saveChanges || 'Save Changes'}
                        </button>
                    </div>
                </div>
            </div>
       
       

            <style>
                /* Toggle switch blue when ON */
                #srk-use-default-settings:checked + .srk-toggle-slider {
                    background-color: #007cba !important;
                }
               
                #srk-use-default-settings:checked + .srk-toggle-slider .srk-toggle-knob {
                    transform: translateX(26px) !important;
                }
            </style>
        `;

        // Tab switching JavaScript function
        function setupTabSwitching() {
            const tabs = document.querySelectorAll('.srk-classic-tab');

            tabs.forEach(tab => {
                tab.addEventListener('click', function () {
                    const tabId = this.getAttribute('data-tab');

                    // Remove active class from all tabs
                    tabs.forEach(t => {
                        t.classList.remove('active');
                        t.style.fontWeight = '500';
                        t.style.color = '#646970';

                        // Hide indicator for all tabs
                        const indicator = t.querySelector('.tab-indicator');
                        if (indicator) {
                            indicator.style.background = 'transparent';
                        }
                    });

                    // Add active class to clicked tab
                    this.classList.add('active');
                    this.style.fontWeight = '600';
                    this.style.color = '#007cba';

                    // Show indicator for active tab
                    const activeIndicator = this.querySelector('.tab-indicator');
                    if (activeIndicator) {
                        activeIndicator.style.background = '#007cba';
                    }

                    // Hide all tab contents
                    document.querySelectorAll('.srk-tab-content').forEach(content => {
                        content.classList.remove('active');
                        content.style.display = 'none';
                    });

                    // Show active tab content
                    const activeContent = document.querySelector(`.srk-tab-content[data-tab="${tabId}"]`);
                    if (activeContent) {
                        activeContent.classList.add('active');
                        activeContent.style.display = 'block';
                    }
                });
            });
        }

        $('#srk-metabox-container').html(uiContent);
        console.log('✅ Classic UI with tabs injected');
        setupTabSwitching();
        // Create React container for Gutenberg modals
        createReactModalContainer();

        // Initialize components
        setTimeout(() => {
            initializeClassicComponents();
        }, 100);
    }

    /**
     * Create React modal container for Gutenberg modals
     */
    function createReactModalContainer() {
        console.log('⚛️ Creating React modal container...');

        $('#srk-react-modal-container').remove();

        const reactContainer = $('<div>', {
            id: 'srk-react-modal-container',
            style: 'position: fixed; z-index: 100000; top: 0; left: 0; width: 100%; height: 100%; display: none;'
        }).appendTo('body');

        console.log('✅ React modal container created');
    }

    /**
 * Initialize Classic Editor components
 */
    function initializeClassicComponents() {
        console.log('🔧 Initializing Classic components with tabs...');

        // Setup visual inputs
        setupVisualInput('title');
        setupVisualInput('description');

        // Load relevant tags
        loadRelevantTags('title', '#srk-title-tags');
        loadRelevantTags('description', '#srk-description-tags');

        // Update advanced settings UI
        updateAdvancedSettingsUI();

        // Initialize conditional fields - YEH NAYA CODE
        initConditionalFields();

        // Bind events
        bindClassicEvents();

        // Integrate with WordPress form
        integrateWithWordPressForm();

        // Update preview
        updatePreview();

        // Initially hide non-active tab content
        $('.srk-tab-content').not('.active').hide();

        console.log('✅ Classic components with tabs initialized');
    }

    /**
     * Setup visual tag input
     */
    function setupVisualInput(type) {
        console.log('⚙️ Setting up visual input for:', type);

        const defaultTemplate = type === 'title' ?
            srkData.defaultTitleTemplate || '%title% %sep% %site_title%' :
            srkData.defaultDescTemplate || '%excerpt%';

        let currentValue = $(`.srk-value-input[data-type="${type}"]`).val();

        if (!currentValue || currentValue === '') {
            currentValue = $(`#srk_meta_${type === 'description' ? 'description' : 'title'}`).val();
        }

        if (!currentValue || currentValue === '') {
            currentValue = defaultTemplate;
        }

        console.log('📝 Setting value for', type, ':', currentValue);

        const placeholder = processTemplateClassic(defaultTemplate, getPreviewData());

        renderDisplayFromValue(currentValue, type);
        $(`.srk-value-input[data-type="${type}"]`).val(currentValue);
        $(`#srk_meta_${type === 'description' ? 'description' : 'title'}`).val(currentValue);
        $(`.srk-tag-display[data-type="${type}"]`).attr('placeholder', placeholder);

        console.log('✅ Visual input setup for:', type, 'value:', currentValue);
    }

    /**
     * Load relevant tags
     */
    function loadRelevantTags(type, container) {
        console.log('🏷️ Loading relevant tags for:', type);

        const relevantTags = srkData.templateTagsRelevant ? srkData.templateTagsRelevant[type] : {};

        if (!relevantTags) {
            $(container).html('<span style="color: #646970; font-size: 12px;">No relevant tags available</span>');
            return;
        }

        let html = '';

        $.each(relevantTags, function (name, tag) {
            html += `
                <button type="button" class="srk-relevant-tag" data-tag="${tag}" data-type="${type}" style="cursor: pointer; padding: 3px 6px; background: #f0f0f1; border: 1px solid #dcdcde; border-radius: 3px; font-size: 12px; display: inline-flex; align-items: center; gap: 4px; margin: 2px;">
                    <span style="font-weight: bold; color: #2271b1;">+</span>
                    <span style="color: #1d2327;">${name}</span>
                </button>
            `;
        });

        html += `
            <a href="#" class="srk-view-all-tags" data-type="${type}"
               style="margin-left: 8px; font-size: 12px; color: #007cba; text-decoration: none; cursor: pointer;">
                ${srkData.i18n?.viewAllTags || 'View all tags →'}
            </a>
        `;

        $(container).html(html);
        console.log('✅ Relevant tags loaded for:', type);
    }

    /**
     * Render display from value
     */
    function renderDisplayFromValue(value, type) {
        console.log('🎨 Rendering display for', type, 'with value:', value);

        const segments = parseValueToSegments(value || '');
        let html = '';
        let tagIndex = 0;

        for (let i = 0; i < segments.length; i++) {
            if (segments[i].type === 'tag') {
                const tag = segments[i].value;
                const displayText = tagToDisplay(tag);

                html += `<span class="srk-tag-chip" contenteditable="false" data-tag="${escapeHtml(tag)}" data-tag-index="${tagIndex}" data-type="${type}" style="display: inline-flex; align-items: center; background: #f0f0f0; border-radius: 3px; padding: 2px 6px 2px 8px; margin: 0 2px; font-size: 12px; line-height: 1.5; border: 1px solid #ddd; cursor: pointer; user-select: none;">
                    <span style="margin-right: 4px;">${displayText}</span>
                    <span class="srk-tag-dropdown" style="display: inline-flex; align-items: center; justify-content: center; width: 16px; height: 16px; cursor: pointer;">
                        <svg width="8" height="8" viewBox="0 0 8 8" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M4 6L1 3H7L4 6Z" fill="#333"></path>
                        </svg>
                    </span>
                </span>`;
                tagIndex++;
            } else {
                const text = escapeHtml(segments[i].value || '');
                html += text;
            }
        }

        if (html === '') {
            const defaultTemplate = type === 'title' ?
                srkData.defaultTitleTemplate || '%title% %sep% %site_title%' :
                srkData.defaultDescTemplate || '%excerpt%';
            const placeholder = processTemplateClassic(defaultTemplate, getPreviewData());
            html = `<span style="color: #999;">${placeholder}</span>`;
        }

        $(`.srk-tag-display[data-type="${type}"]`).html(html);
        $(`.srk-value-input[data-type="${type}"]`).val(value);

        console.log('✅ Display rendered for', type);
    }

    /**
     * Parse value into segments
     */
    function parseValueToSegments(value) {
        const segments = [];
        if (!value) return segments;

        const tagRegex = /%[a-z_]+%/gi;
        let lastEnd = 0;
        let match;

        while ((match = tagRegex.exec(value)) !== null) {
            if (match.index > lastEnd) {
                segments.push({
                    type: 'text',
                    value: value.substring(lastEnd, match.index)
                });
            }

            segments.push({
                type: 'tag',
                value: match[0]
            });

            lastEnd = match.index + match[0].length;
        }

        if (lastEnd < value.length) {
            segments.push({
                type: 'text',
                value: value.substring(lastEnd)
            });
        }

        return segments;
    }

    /**
     * Update value from display
     */
    function updateValueFromDisplay(type) {
        console.log('🔄 Updating value from display for:', type);

        const display = $(`.srk-tag-display[data-type="${type}"]`);
        const hidden = $(`.srk-value-input[data-type="${type}"]`);

        if (!display.length || !hidden.length) {
            console.error('❌ Elements not found for update:', type);
            return;
        }

        let value = '';

        display.contents().each(function () {
            const node = $(this);

            if (node.hasClass('srk-tag-chip')) {
                value += node.data('tag') || '';
            } else if (this.nodeType === 3) {
                value += this.textContent || '';
            } else {
                value += node.text() || '';
            }
        });

        console.log('📝 New value for', type, ':', value);
        hidden.val(value);

        $(`#srk_meta_${type === 'description' ? 'description' : 'title'}`).val(value);

        markAsDirty();
        scheduleAutoSave();
        updatePreview();
    }

    /**
     * Show save status
     */
    function showSaveStatus(message, type = 'saving') {
        const $status = $('#srk-save-status');
        const $icon = $('#srk-save-status-icon');
        const $text = $('#srk-save-status-text');

        $text.text(message);

        switch (type) {
            case 'saving':
                $icon.text('⏳');
                $status.css({
                    'background': '#f0f6fc',
                    'border-color': '#c3d4e9'
                });
                break;
            case 'success':
                $icon.text('✅');
                $status.css({
                    'background': '#f0f9f4',
                    'border-color': '#b8e6bf'
                });
                break;
            case 'error':
                $icon.text('❌');
                $status.css({
                    'background': '#fcf0f1',
                    'border-color': '#f5c2c7'
                });
                break;
        }

        $status.slideDown();

        if (type !== 'saving') {
            setTimeout(() => {
                $status.slideUp();
            }, 3000);
        }
    }

    /**
     * Hide save status
     */
    function hideSaveStatus() {
        $('#srk-save-status').slideUp();
    }

    /**
     * Schedule auto-save
     */
    function scheduleAutoSave() {
        if (saveTimeout) {
            clearTimeout(saveTimeout);
        }

        saveTimeout = setTimeout(function () {
            console.log('⏰ Auto-saving changes...');
            showSaveStatus('Auto-saving changes...', 'saving');
            saveMetaData();
        }, 2000);
    }
    /**
 * Initialize conditional fields for Classic Editor
 */
    function initConditionalFields() {
        console.log('🔧 Initializing conditional fields...');

        // Function to toggle max snippet field
        function toggleMaxSnippet() {
            const isNoSnippet = $('#srk-robots-nosnippet').is(':checked');
            if (isNoSnippet) {
                $('#srk-max-snippet-wrapper').slideUp(200);
            } else {
                $('#srk-max-snippet-wrapper').slideDown(200);
            }
        }

        // Function to toggle max image preview field
        function toggleMaxImagePreview() {
            const isNoImageIndex = $('#srk-robots-noimageindex').is(':checked');
            if (isNoImageIndex) {
                $('#srk-max-image-wrapper').slideUp(200);
            } else {
                $('#srk-max-image-wrapper').slideDown(200);
            }
        }

        // Initial state
        toggleMaxSnippet();
        toggleMaxImagePreview();

        // Bind events
        $(document).off('change', '#srk-robots-nosnippet').on('change', '#srk-robots-nosnippet', toggleMaxSnippet);
        $(document).off('change', '#srk-robots-noimageindex').on('change', '#srk-robots-noimageindex', toggleMaxImagePreview);

        // When use default settings toggles
        $(document).off('change', '#srk-use-default-settings').on('change', '#srk-use-default-settings', function () {
            const isChecked = $(this).is(':checked');
            if (!isChecked) {
                // Re-apply conditional visibility when settings are shown
                setTimeout(() => {
                    toggleMaxSnippet();
                    toggleMaxImagePreview();
                }, 300);
            }
        });

        console.log('✅ Conditional fields initialized');
    }
    /**
     * Bind all Classic Editor events
     */
    function bindClassicEvents() {
        console.log('🔗 Binding Classic Editor events with tabs...');

        // Tab switching
        $(document)
            .on('click', '.srk-classic-tab', function (e) {
                e.preventDefault();
                const tab = $(this).data('tab');
                activeTab = tab;

                // Update active tab
                $('.srk-classic-tab').removeClass('active');
                $(this).addClass('active');

                // Hide all tab contents
                $('.srk-tab-content').removeClass('active').hide();

                // Show selected tab content
                $(`.srk-tab-content[data-tab="${tab}"]`).addClass('active').show();
            })

            // Edit Snippet button
            .on('click', '#srk-edit-snippet', function (e) {
                e.preventDefault();
                showEditSnippetModal();
            })

            // Modal tab switching
            .on('click', '.srk-modal-tab', function (e) {
                e.preventDefault();
                const tab = $(this).data('tab');

                $('.srk-modal-tab').removeClass('active');
                $(this).addClass('active');

                loadModalTabContent(tab);
            })

            // Modal close
            .on('click', '#srk-modal-close, #srk-modal-cancel', function (e) {
                e.preventDefault();
                $('#srk-edit-snippet-modal').hide();
            })

            // Modal save
            .on('click', '#srk-modal-save', function (e) {
                e.preventDefault();
                saveFromModal();
            })

            // Modal reset
            .on('click', '#srk-modal-reset', function (e) {
                e.preventDefault();
                if (confirm('Are you sure you want to reset to defaults?')) {
                    resetModalToDefaults();
                }
            })

            // Save button
            .on('click', '#srk-save', function (e) {
                e.preventDefault();
                showSaveStatus('Saving changes...', 'saving');
                saveMetaData();
            })

            // Reset buttons
            .on('click', '#srk-reset-all', function (e) {
                e.preventDefault();
                resetAllToDefaults();
            })
            .on('click', '.srk-reset-btn', function (e) {
                e.preventDefault();
                const type = $(this).data('type');
                resetToDefaultTemplate(type);
            })

            // Advanced settings toggle
            .on('change', '#srk-use-default-settings', function () {
                const isChecked = $(this).is(':checked');
                advancedSettings.use_default_settings = isChecked ? '1' : '0';

                if (isChecked) {
                    $('.srk-robots-settings-container').slideUp();
                } else {
                    $('.srk-robots-settings-container').slideDown();
                }

                markAsDirty();
                scheduleAutoSave();
            })

            // Apply content type settings
            .on('click', '#srk-apply-content-type-settings', function (e) {
                e.preventDefault();
                if (confirm(srkData.i18n?.applyWarning || 'This will override your current advanced settings with the content type defaults. Continue?')) {
                    applyContentTypeSettings();
                }
            })

            // Robots meta checkboxes
            .on('change', '[id^="srk-robots-"]', function () {
                const id = $(this).attr('id');
                const key = id.replace('srk-robots-', '');

                if (advancedSettings.robots_meta) {
                    advancedSettings.robots_meta[key] = $(this).is(':checked') ? '1' : '0';
                    markAsDirty();
                    scheduleAutoSave();
                }
            })

            // Max values inputs
            .on('input', '#srk-max-snippet, #srk-max-video-preview', function () {
                const id = $(this).attr('id');
                const key = id.replace('srk-', '').replace(/-/g, '_');

                if (advancedSettings.robots_meta) {
                    advancedSettings.robots_meta[key] = $(this).val();
                    markAsDirty();
                    scheduleAutoSave();
                }
            })

            .on('change', '#srk-max-image-preview', function () {
                if (advancedSettings.robots_meta) {
                    advancedSettings.robots_meta.max_image_preview = $(this).val();
                    markAsDirty();
                    scheduleAutoSave();
                }
            })

            // Insert relevant tag
            .on('click', '.srk-relevant-tag', function (e) {
                e.preventDefault();
                const tag = $(this).data('tag');
                const type = $(this).data('type');
                console.log('➕ Inserting relevant tag:', tag, 'into:', type);
                insertTag(tag, type);
            })

            // View all tags
            .on('click', '.srk-view-all-tags', function (e) {
                e.preventDefault();
                const type = $(this).data('type');
                console.log('📋 Opening GUTENBERG tag modal for:', type);
                showGutenbergTagModal(type);
            })

            // Tag chip click (edit)
            .on('click', '.srk-tag-chip', function (e) {
                e.preventDefault();
                e.stopPropagation();

                const tag = $(this).data('tag');
                const type = $(this).data('type');
                const tagIndex = $(this).data('tag-index');

                console.log('✏️ Tag chip clicked:', tag, 'type:', type, 'index:', tagIndex);

                if ($(e.target).hasClass('srk-tag-dropdown') || $(e.target).closest('.srk-tag-dropdown').length) {
                    showEditTagModal(tag, type, tagIndex);
                }
            });

        // Canonical URL input
        $('#srk-canonical-input').on('input', function () {
            $('#srk_canonical_url').val($(this).val());
            markAsDirty();
            scheduleAutoSave();
            updatePreview();
        });
        // Track changes for save button
        $('#srk-canonical-input').on('input', checkFormChanges);
        $('.srk-value-input').on('input', checkFormChanges);
        // Input display events
        $('.srk-tag-display').on('input', function () {
            const type = $(this).data('type');
            updateValueFromDisplay(type);
        });

        $('.srk-tag-display').on('keyup', function () {
            const type = $(this).data('type');
            updateValueFromDisplay(type);
        });

        $('.srk-tag-display').on('paste', function (e) {
            e.preventDefault();
            const text = (e.originalEvent || e).clipboardData.getData('text/plain');
            document.execCommand('insertText', false, text);

            setTimeout(() => {
                const type = $(this).data('type');
                updateValueFromDisplay(type);
            }, 100);
        });

        $('.srk-tag-display').on('focus', function () {
            $(this).closest('.srk-visual-input').addClass('srk-focused');
            $(this).removeAttr('placeholder');
        });

        $('.srk-tag-display').on('blur', function () {
            $(this).closest('.srk-visual-input').removeClass('srk-focused');

            const type = $(this).data('type');
            updateValueFromDisplay(type);

            if ($(this).text().trim() === '') {
                const defaultTemplate = type === 'title' ?
                    srkData.defaultTitleTemplate || '%title% %sep% %site_title%' :
                    srkData.defaultDescTemplate || '%excerpt%';
                $(this).attr('placeholder', processTemplateClassic(defaultTemplate, getPreviewData()));
            }
        });

        console.log('✅ Classic Editor events with tabs bound');
    }

    /**
     * Show Edit Snippet Modal
     */
    function showEditSnippetModal() {
        console.log('📋 Showing Edit Snippet Modal');

        // Show modal
        $('#srk-edit-snippet-modal').show();

        // Load initial tab content
        loadModalTabContent('title-description');
    }

    /**
     * Load modal tab content
     */
    function loadModalTabContent(tab) {
        console.log('📄 Loading modal tab:', tab);

        const $content = $('#srk-modal-content');
        $content.empty();

        if (tab === 'title-description') {
            const metaTitle = $(`.srk-value-input[data-type="title"]`).val();
            const metaDescription = $(`.srk-value-input[data-type="description"]`).val();
            const canonicalUrl = $('#srk-canonical-input').val();

            const previewData = getPreviewData();
            const defaultTitleTemplate = srkData.defaultTitleTemplate || '%title% %sep% %site_title%';
            const defaultDescTemplate = srkData.defaultDescTemplate || '%excerpt%';

            $content.html(`
                <div style="margin-bottom: 20px;">
                    <div style="margin-bottom: 8px;">
                        <label style="font-weight: 600; font-size: 13px; color: #1d2327; display: block; margin-bottom: 5px;">
                            ${srkData.i18n?.seoTitle || 'SEO Title'}
                        </label>
                        <div id="srk-modal-title-tags" style="margin-bottom: 8px; display: flex; flex-wrap: wrap; align-items: center; gap: 4px;"></div>
                        <input type="text" id="srk-modal-title-input" class="regular-text" value="${metaTitle || defaultTitleTemplate}" style="width: 100%;">
                        <p style="margin: 4px 0 0 0; font-size: 12px; color: #646970;">
                            ${srkData.i18n?.titleHelp || 'Title shown in search results'}
                        </p>
                    </div>
                   
                    <div style="margin-bottom: 20px;">
                        <label style="font-weight: 600; font-size: 13px; color: #1d2327; display: block; margin-bottom: 5px;">
                            ${srkData.i18n?.metaDescription || 'Meta Description'}
                        </label>
                        <div id="srk-modal-desc-tags" style="margin-bottom: 8px; display: flex; flex-wrap: wrap; align-items: center; gap: 4px;"></div>
                        <textarea id="srk-modal-desc-input" rows="3" style="width: 100%;">${metaDescription || defaultDescTemplate}</textarea>
                        <p style="margin: 4px 0 0 0; font-size: 12px; color: #646970;">
                            ${srkData.i18n?.descHelp || 'Description shown in search results'}
                        </p>
                    </div>
                   
                    <div>
                        <label style="font-weight: 600; font-size: 13px; color: #1d2327; display: block; margin-bottom: 5px;">
                            ${srkData.i18n?.canonicalUrl || 'Canonical URL'}
                        </label>
                        <input type="text" id="srk-modal-canonical-input" class="regular-text" value="${canonicalUrl || ''}" placeholder="${srkData.siteUrl || 'https://example.com'}/your-page/" style="width: 100%;">
                        <p style="margin: 4px 0 0 0; font-size: 12px; color: #646970;">
                            ${srkData.i18n?.canonicalHelp || 'Preferred URL for this content'}
                        </p>
                    </div>
                </div>
               
                <!-- Live Preview -->
                <div style="margin-top: 30px; padding: 15px; background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 4px;">
                    <strong style="display: block; margin-bottom: 10px;">
                        ${srkData.i18n?.preview || 'Live Preview:'}
                    </strong>
                    <div id="srk-modal-preview-url" style="color: #70757a; font-size: 12px; margin-bottom: 8px; font-family: Arial, sans-serif;">
                        ${srkData.siteUrl ? srkData.siteUrl.replace(/^https?:\/\//, '') : 'example.com'}
                    </div>
                    <div id="srk-modal-preview-title" style="color: #1a0dab; font-size: 16px; margin-bottom: 8px; font-family: Arial, sans-serif; font-weight: 400; line-height: 1.4;">
                        ${processTemplateClassic(metaTitle || defaultTitleTemplate, previewData)}
                    </div>
                    <div id="srk-modal-preview-description" style="color: #3c4043; font-size: 14px; font-family: Arial, sans-serif; line-height: 1.5;">
                        ${processTemplateClassic(metaDescription || defaultDescTemplate, previewData)}
                    </div>
                </div>
            `);

            // Load tags for modal
            loadModalRelevantTags('title', '#srk-modal-title-tags');
            loadModalRelevantTags('description', '#srk-modal-desc-tags');

            // Bind modal input events
            $('#srk-modal-title-input, #srk-modal-desc-input, #srk-modal-canonical-input').on('input', updateModalPreview);

        } else if (tab === 'advanced') {
            $content.html(`
        <div class="srk-advanced-section">
            <!-- Use Default Settings Toggle -->
            <div style="margin-bottom: 20px;">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px;">
                    <label class="srk-toggle-switch" style="position: relative; display: inline-block; width: 50px; height: 24px;">
                        <input type="checkbox" id="srk-modal-use-default-settings" ${advancedSettings.use_default_settings === '1' ? 'checked' : ''}>
                        <span class="srk-toggle-slider" style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 24px;">
                            <span class="srk-toggle-knob" style="position: absolute; height: 16px; width: 16px; left: 4px; bottom: 4px; background-color: white; transition: .4s; border-radius: 50%;"></span>
                        </span>
                    </label>
                    <span style="font-size: 13px; color: #1d2327; font-weight: 500;">
                        ${srkData.i18n?.useDefaultSettings || 'Use Default Settings'}
                    </span>
                </div>
            </div>
           
            <!-- Robots Meta Settings -->
            <div id="srk-modal-robots-container" style="${advancedSettings.use_default_settings === '1' ? 'display: none;' : ''}">
                <h4 style="margin-bottom: 15px; color: #1d2327;">
                    ${srkData.i18n?.robotsMeta || 'Robots Meta Settings'}
                </h4>
               
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 20px;">
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" id="srk-modal-robots-noindex" ${advancedSettings.robots_meta?.noindex === '1' ? 'checked' : ''}>
                        <span>${srkData.i18n?.noIndex || 'No Index'}</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" id="srk-modal-robots-nofollow" ${advancedSettings.robots_meta?.nofollow === '1' ? 'checked' : ''}>
                        <span>${srkData.i18n?.noFollow || 'No Follow'}</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" id="srk-modal-robots-noarchive" ${advancedSettings.robots_meta?.noarchive === '1' ? 'checked' : ''}>
                        <span>${srkData.i18n?.noArchive || 'No Archive'}</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" id="srk-modal-robots-notranslate" ${advancedSettings.robots_meta?.notranslate === '1' ? 'checked' : ''}>
                        <span>${srkData.i18n?.noTranslate || 'No Translate'}</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" id="srk-modal-robots-noimageindex" ${advancedSettings.robots_meta?.noimageindex === '1' ? 'checked' : ''}>
                        <span>${srkData.i18n?.noImageIndex || 'No Image Index'}</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" id="srk-modal-robots-nosnippet" ${advancedSettings.robots_meta?.nosnippet === '1' ? 'checked' : ''}>
                        <span>${srkData.i18n?.noSnippet || 'No Snippet'}</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" id="srk-modal-robots-noodp" ${advancedSettings.robots_meta?.noodp === '1' ? 'checked' : ''}>
                        <span>${srkData.i18n?.noOdp || 'No ODP'}</span>
                    </label>
                </div>
               
                <!-- Max Values - WITH CONDITIONAL DISPLAY -->
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-top: 20px;">
                    <!-- MAX SNIPPET - Will be hidden when No Snippet is checked -->
                    <div id="srk-modal-max-snippet-wrapper" style="display: block;">
                        <label for="srk-modal-max-snippet" style="display: block; margin-bottom: 5px; font-size: 12px; font-weight: 600; color: #1d2327;">
                            ${srkData.i18n?.maxSnippet || 'Max Snippet'}
                        </label>
                        <input type="number" id="srk-modal-max-snippet" class="small-text" min="-1" step="1" style="width: 100%;" value="${advancedSettings.robots_meta?.max_snippet || -1}">
                    </div>
                   
                    <!-- MAX VIDEO PREVIEW - Always visible -->
                    <div>
                        <label for="srk-modal-max-video-preview" style="display: block; margin-bottom: 5px; font-size: 12px; font-weight: 600; color: #1d2327;">
                            ${srkData.i18n?.maxVideoPreview || 'Max Video Preview'}
                        </label>
                        <input type="number" id="srk-modal-max-video-preview" class="small-text" min="-1" step="1" style="width: 100%;" value="${advancedSettings.robots_meta?.max_video_preview || -1}">
                    </div>
                   
                    <!-- MAX IMAGE PREVIEW - Will be hidden when No Image Index is checked -->
                    <div id="srk-modal-max-image-wrapper" style="display: block;">
                        <label for="srk-modal-max-image-preview" style="display: block; margin-bottom: 5px; font-size: 12px; font-weight: 600; color: #1d2327;">
                            ${srkData.i18n?.maxImagePreview || 'Max Image Preview'}
                        </label>
                        <select id="srk-modal-max-image-preview" style="width: 100%;">
                            <option value="none" ${advancedSettings.robots_meta?.max_image_preview === 'none' ? 'selected' : ''}>${srkData.i18n?.none || 'None'}</option>
                            <option value="standard" ${advancedSettings.robots_meta?.max_image_preview === 'standard' ? 'selected' : ''}>${srkData.i18n?.standard || 'Standard'}</option>
                            <option value="large" ${advancedSettings.robots_meta?.max_image_preview === 'large' ? 'selected' : ''}>${srkData.i18n?.large || 'Large'}</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    `);

            // Bind modal advanced settings events WITH CONDITIONAL FIELDS
            function initModalConditionalFields() {
                function toggleModalMaxSnippet() {
                    const isNoSnippet = $('#srk-modal-robots-nosnippet').is(':checked');
                    if (isNoSnippet) {
                        $('#srk-modal-max-snippet-wrapper').slideUp(200);
                    } else {
                        $('#srk-modal-max-snippet-wrapper').slideDown(200);
                    }
                }

                function toggleModalMaxImagePreview() {
                    const isNoImageIndex = $('#srk-modal-robots-noimageindex').is(':checked');
                    if (isNoImageIndex) {
                        $('#srk-modal-max-image-wrapper').slideUp(200);
                    } else {
                        $('#srk-modal-max-image-wrapper').slideDown(200);
                    }
                }

                // Initial state
                toggleModalMaxSnippet();
                toggleModalMaxImagePreview();

                // Bind events
                $('#srk-modal-robots-nosnippet').off('change').on('change', toggleModalMaxSnippet);
                $('#srk-modal-robots-noimageindex').off('change').on('change', toggleModalMaxImagePreview);
            }

            $('#srk-modal-use-default-settings').off('change').on('change', function () {
                const isChecked = $(this).is(':checked');
                if (isChecked) {
                    $('#srk-modal-robots-container').slideUp(200);
                } else {
                    $('#srk-modal-robots-container').slideDown(200, function () {
                        initModalConditionalFields();
                    });
                }
            });

            $('#srk-modal-apply-content-type').off('click').on('click', function () {
                if (confirm(srkData.i18n?.applyWarning || 'This will override your current advanced settings with the content type defaults. Continue?')) {
                    applyContentTypeSettingsToModal();
                    setTimeout(initModalConditionalFields, 300);
                }
            });

            // Initialize modal conditional fields
            initModalConditionalFields();
        }
    }

    /**
     * Load modal relevant tags
     */
    function loadModalRelevantTags(type, container) {
        const relevantTags = srkData.templateTagsRelevant ? srkData.templateTagsRelevant[type] : {};

        if (!relevantTags) return;

        let html = '';

        $.each(relevantTags, function (name, tag) {
            html += `
                <button type="button" class="srk-modal-relevant-tag" data-tag="${tag}" data-type="${type}" style="cursor: pointer; padding: 3px 6px; background: #f0f0f1; border: 1px solid #dcdcde; border-radius: 3px; font-size: 12px; display: inline-flex; align-items: center; gap: 4px; margin: 2px;">
                    <span style="font-weight: bold; color: #2271b1;">+</span>
                    <span style="color: #1d2327;">${name}</span>
                </button>
            `;
        });

        html += `
            <a href="#" class="srk-modal-view-all-tags" data-type="${type}"
               style="margin-left: 8px; font-size: 12px; color: #007cba; text-decoration: none; cursor: pointer;">
                ${srkData.i18n?.viewAllTags || 'View all tags →'}
            </a>
        `;

        $(container).html(html);

        // Bind modal tag events
        $(document).off('click', '.srk-modal-relevant-tag').on('click', '.srk-modal-relevant-tag', function (e) {
            e.preventDefault();
            const tag = $(this).data('tag');
            const type = $(this).data('type');
            insertTagIntoModal(tag, type);
        });
    }

    /**
     * Update modal preview
     */
    function updateModalPreview() {
        const title = $('#srk-modal-title-input').val();
        const description = $('#srk-modal-desc-input').val();

        const titlePreview = processTemplateClassic(title, getPreviewData());
        const descPreview = processTemplateClassic(description, getPreviewData());

        $('#srk-modal-preview-title').text(titlePreview || srkData.i18n?.noTitle || '(No title)');
        $('#srk-modal-preview-description').text(descPreview || srkData.i18n?.noDescription || '(No description)');
    }

    /**
     * Insert tag into modal input
     */
    function insertTagIntoModal(tag, type) {
        const inputId = type === 'title' ? '#srk-modal-title-input' : '#srk-modal-desc-input';
        const $input = $(inputId);
        const currentValue = $input.val() || '';
        const cursorPos = $input[0].selectionStart;
        const before = currentValue.substring(0, cursorPos);
        const after = currentValue.substring(cursorPos);
        const newValue = before + (before && !before.endsWith(' ') ? ' ' : '') + tag + ' ' + after;
        $input.val(newValue);
        updateModalPreview();
    }

    /**
     * Apply content type settings to modal
     */
    function applyContentTypeSettingsToModal() {
        const contentTypeRobots = srkData.contentTypeRobots || {};

        // Update modal checkboxes
        $('#srk-modal-robots-noindex').prop('checked', contentTypeRobots.noindex === '1');
        $('#srk-modal-robots-nofollow').prop('checked', contentTypeRobots.nofollow === '1');
        $('#srk-modal-robots-noarchive').prop('checked', contentTypeRobots.noarchive === '1');
        $('#srk-modal-robots-notranslate').prop('checked', contentTypeRobots.notranslate === '1');
        $('#srk-modal-robots-noimageindex').prop('checked', contentTypeRobots.noimageindex === '1');
        $('#srk-modal-robots-nosnippet').prop('checked', contentTypeRobots.nosnippet === '1');
        $('#srk-modal-robots-noodp').prop('checked', contentTypeRobots.noodp === '1');
        $('#srk-modal-max-snippet').val(contentTypeRobots.max_snippet || -1);
        $('#srk-modal-max-video-preview').val(contentTypeRobots.max_video_preview || -1);
        $('#srk-modal-max-image-preview').val(contentTypeRobots.max_image_preview || 'large');

        // Update advanced settings object
        advancedSettings = {
            robots_meta: contentTypeRobots,
            use_default_settings: '0'
        };

        // Show success message
        alert('Content type settings applied successfully!');
    }

    /**
     * Save from modal
     */
    function saveFromModal() {
        const activeTab = $('.srk-modal-tab.active').data('tab');

        if (activeTab === 'title-description') {
            // Save title and description
            const titleValue = $('#srk-modal-title-input').val();
            const descValue = $('#srk-modal-desc-input').val();
            const canonicalValue = $('#srk-modal-canonical-input').val();

            $(`.srk-value-input[data-type="title"]`).val(titleValue);
            $(`.srk-value-input[data-type="description"]`).val(descValue);
            $('#srk-canonical-input').val(canonicalValue);

            // Update hidden fields
            $('#srk_meta_title').val(titleValue);
            $('#srk_meta_description').val(descValue);
            $('#srk_canonical_url').val(canonicalValue);

            // Update displays
            renderDisplayFromValue(titleValue, 'title');
            renderDisplayFromValue(descValue, 'description');

            markAsDirty();
            updatePreview();

        } else if (activeTab === 'advanced') {
            // Save advanced settings
            advancedSettings.use_default_settings = $('#srk-modal-use-default-settings').is(':checked') ? '1' : '0';

            if (advancedSettings.use_default_settings !== '1') {
                advancedSettings.robots_meta = {
                    noindex: $('#srk-modal-robots-noindex').is(':checked') ? '1' : '0',
                    nofollow: $('#srk-modal-robots-nofollow').is(':checked') ? '1' : '0',
                    noarchive: $('#srk-modal-robots-noarchive').is(':checked') ? '1' : '0',
                    notranslate: $('#srk-modal-robots-notranslate').is(':checked') ? '1' : '0',
                    noimageindex: $('#srk-modal-robots-noimageindex').is(':checked') ? '1' : '0',
                    nosnippet: $('#srk-modal-robots-nosnippet').is(':checked') ? '1' : '0',
                    noodp: $('#srk-modal-robots-noodp').is(':checked') ? '1' : '0',
                    max_snippet: $('#srk-modal-max-snippet').val(),
                    max_video_preview: $('#srk-modal-max-video-preview').val(),
                    max_image_preview: $('#srk-modal-max-image-preview').val()
                };
            }

            // Update main UI
            updateAdvancedSettingsUI();

            // Update hidden field
            $('#srk_advanced_settings').val(JSON.stringify(advancedSettings));

            markAsDirty();
        }

        // Show saving indicator
        $('#srk-modal-saving').show();
        $('#srk-modal-save').prop('disabled', true).text(srkData.i18n?.saving || 'Saving...');

        // Save via AJAX
        saveMetaData(true).then(() => {
            // Close modal after save
            setTimeout(() => {
                $('#srk-edit-snippet-modal').hide();
                $('#srk-modal-saving').hide();
                $('#srk-modal-save').prop('disabled', false).text(srkData.i18n?.saveChanges || 'Save Changes');

                // Show success message
                showSaveStatus(srkData.i18n?.saved || 'Settings saved successfully!', 'success');
            }, 500);
        });
    }

    /**
     * Reset modal to defaults
     */
    function resetModalToDefaults() {
        const activeTab = $('.srk-modal-tab.active').data('tab');

        if (activeTab === 'title-description') {
            const defaultTitle = srkData.defaultTitleTemplate || '%title% %sep% %site_title%';
            const defaultDesc = srkData.defaultDescTemplate || '%excerpt%';

            $('#srk-modal-title-input').val(defaultTitle);
            $('#srk-modal-desc-input').val(defaultDesc);
            $('#srk-modal-canonical-input').val('');

            updateModalPreview();

        } else if (activeTab === 'advanced') {
            // Reset to default advanced settings
            advancedSettings = {
                robots_meta: {
                    noindex: '0',
                    nofollow: '0',
                    noarchive: '0',
                    notranslate: '0',
                    noimageindex: '0',
                    nosnippet: '0',
                    noodp: '0',
                    max_snippet: -1,
                    max_video_preview: -1,
                    max_image_preview: 'large'
                },
                use_default_settings: '0'
            };

            // Update modal UI
            $('#srk-modal-use-default-settings').prop('checked', false);
            $('#srk-modal-robots-noindex').prop('checked', false);
            $('#srk-modal-robots-nofollow').prop('checked', false);
            $('#srk-modal-robots-noarchive').prop('checked', false);
            $('#srk-modal-robots-notranslate').prop('checked', false);
            $('#srk-modal-robots-noimageindex').prop('checked', false);
            $('#srk-modal-robots-nosnippet').prop('checked', false);
            $('#srk-modal-robots-noodp').prop('checked', false);
            $('#srk-modal-max-snippet').val(-1);
            $('#srk-modal-max-video-preview').val(-1);
            $('#srk-modal-max-image-preview').val('large');

            $('#srk-modal-robots-container').show();
        }
    }

    /**
     * Apply content type settings
     */
    function applyContentTypeSettings() {
        const contentTypeRobots = srkData.contentTypeRobots || {};

        advancedSettings = {
            robots_meta: contentTypeRobots,
            use_default_settings: '0'
        };

        updateAdvancedSettingsUI();
        markAsDirty();
        scheduleAutoSave();

        // Show success message
        showSaveStatus('Content type settings applied successfully!', 'success');
    }

    /**
     * Show Gutenberg Tag Modal in Classic Editor
     */
    function showGutenbergTagModal(type) {
        console.log('🎬 Showing Gutenberg TagModal in Classic Editor for:', type);

        if (!window.SRK_Gutenberg || !window.SRK_Gutenberg.Modal || !window.SRK_Gutenberg.element) {
            console.error('❌ Gutenberg components not available');
            alert('Gutenberg components not loaded. Please refresh the page.');
            return;
        }

        const Modal = window.SRK_Gutenberg.Modal;
        const Button = window.SRK_Gutenberg.components.Button;
        const { useState, useEffect, createElement } = window.SRK_Gutenberg.element;

        const allTags = srkData.templateTags || {};
        const relevantTags = srkData.templateTagsRelevant || {};

        const ClassicTagModalComponent = () => {
            const [searchTerm, setSearchTerm] = useState('');
            const [filteredTags, setFilteredTags] = useState([]);
            const [isOpen, setIsOpen] = useState(true);

            useEffect(() => {
                if (!allTags) return;

                if (searchTerm.trim() === '') {
                    const relevant = relevantTags[type] || {};
                    const relevantTagValues = Object.values(relevant);

                    const sortedTags = Object.entries(allTags).sort(([nameA, dataA], [nameB, dataB]) => {
                        const isRelevantA = relevantTagValues.includes(dataA.tag);
                        const isRelevantB = relevantTagValues.includes(dataB.tag);

                        if (isRelevantA && !isRelevantB) return -1;
                        if (!isRelevantA && isRelevantB) return 1;
                        return nameA.localeCompare(nameB);
                    });

                    setFilteredTags(sortedTags);
                } else {
                    const filtered = Object.entries(allTags).filter(([name, data]) => {
                        const nameMatch = name.toLowerCase().includes(searchTerm.toLowerCase());
                        const descMatch = data.description.toLowerCase().includes(searchTerm.toLowerCase());
                        return nameMatch || descMatch;
                    });
                    setFilteredTags(filtered);
                }
            }, [searchTerm, allTags, type, relevantTags]);

            const handleSelectTag = (tag) => {
                console.log('🏷️ Tag selected in Gutenberg modal:', tag);

                if (activeTab === 'title-description') {
                    insertTag(tag, type);
                } else {
                    // In modal
                    const modalActiveTab = $('.srk-modal-tab.active').data('tab');
                    if (modalActiveTab === 'title-description') {
                        insertTagIntoModal(tag, type);
                    }
                }

                hideGutenbergTagModal();
            };

            const handleClose = () => {
                hideGutenbergTagModal();
            };

            const ModalHeader = () => {
                return createElement('div', {
                    style: {
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'center',
                        padding: '16px 24px',
                        borderBottom: '1px solid #ddd'
                    }
                },
                    createElement('div', {
                        style: {
                            display: 'flex',
                            alignItems: 'center',
                            gap: '15px'
                        }
                    },
                        createElement('h2', {
                            style: {
                                margin: 0,
                                fontSize: '20px',
                                fontWeight: 600,
                                lineHeight: '1.4'
                            }
                        }, srkData.i18n?.selectTag || 'Select a Tag')
                    )
                );
            };

            if (!isOpen) return null;

            return createElement(Modal, {
                title: '',
                onRequestClose: handleClose,
                className: 'srk-tag-modal',
                style: { maxWidth: '500px' },
                headerActions: ModalHeader()
            },
                createElement('div', { style: { padding: '20px' } },
                    createElement('div', { style: { marginBottom: '20px' } },
                        createElement('input', {
                            type: 'text',
                            className: 'srk-search-input',
                            placeholder: srkData.i18n?.searchTags || 'Search for an item...',
                            value: searchTerm,
                            onChange: (e) => setSearchTerm(e.target.value),
                            style: {
                                width: '100%',
                                padding: '8px 12px',
                                border: '1px solid #ddd',
                                borderRadius: '4px',
                                fontSize: '14px'
                            },
                            autoFocus: true
                        })
                    ),

                    createElement('ul', {
                        style: {
                            maxHeight: '300px',
                            overflowY: 'auto',
                            margin: 0,
                            padding: 0,
                            listStyle: 'none',
                            border: '1px solid #eee',
                            borderRadius: '4px'
                        }
                    },
                        filteredTags.map(([name, data]) =>
                            createElement('li', {
                                key: data.tag,
                                className: `srk-tag-item`,
                                onClick: () => handleSelectTag(data.tag),
                                style: {
                                    padding: '10px 15px',
                                    borderBottom: '1px solid #eee',
                                    cursor: 'pointer',
                                    display: 'flex',
                                    alignItems: 'flex-start',
                                    backgroundColor: 'transparent'
                                }
                            },
                                createElement('span', {
                                    style: {
                                        display: 'inline-flex',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                        width: '20px',
                                        height: '20px',
                                        borderRadius: '50%',
                                        background: '#007cba',
                                        color: 'white',
                                        marginRight: '10px',
                                        fontSize: '12px',
                                        fontWeight: 'bold',
                                        flexShrink: 0
                                    }
                                }, '+'),
                                createElement('div', { style: { flex: 1 } },
                                    createElement('h4', {
                                        style: {
                                            margin: '0 0 5px 0',
                                            fontSize: '14px',
                                            fontWeight: 'bold'
                                        }
                                    }, name),
                                    createElement('p', {
                                        style: {
                                            margin: 0,
                                            fontSize: '12px',
                                            color: '#666',
                                            lineHeight: '1.4'
                                        }
                                    }, data.description)
                                )
                            )
                        )
                    ),

                    createElement('div', {
                        style: {
                            display: 'flex',
                            justifyContent: 'flex-end',
                            marginTop: '20px',
                            paddingTop: '20px',
                            borderTop: '1px solid #eee'
                        }
                    },
                        createElement(Button, {
                            isSecondary: true,
                            onClick: handleClose
                        }, srkData.i18n?.cancel || 'Cancel')
                    )
                )
            );
        };

        $('#srk-react-modal-container').show();

        const container = document.getElementById('srk-react-modal-container');
        if (container && window.SRK_Gutenberg.element && window.SRK_Gutenberg.element.render) {
            window.SRK_Gutenberg.element.render(
                createElement(ClassicTagModalComponent),
                container
            );
        }

        console.log('✅ Gutenberg modal shown in Classic Editor');
    }

    /**
     * Hide Gutenberg Tag Modal
     */
    function hideGutenbergTagModal() {
        console.log('👋 Hiding Gutenberg modal');

        const container = document.getElementById('srk-react-modal-container');
        if (container && window.SRK_Gutenberg.element && window.SRK_Gutenberg.element.unmountComponentAtNode) {
            window.SRK_Gutenberg.element.unmountComponentAtNode(container);
            $(container).hide().empty();
        }
    }

    /**
     * Show edit tag modal (for tag chips)
     */
    function showEditTagModal(tag, type, tagIndex) {
        console.log('✏️ Showing Gutenberg Edit/Delete Tag modal for:', tag, 'type:', type, 'index:', tagIndex);

        currentEditingTag = tag;
        currentEditingTagIndex = tagIndex;
        currentEditingTarget = type;

        if (!window.SRK_Gutenberg || !window.SRK_Gutenberg.Modal || !window.SRK_Gutenberg.element) {
            console.error('❌ Gutenberg components not available');
            showSimpleEditTagModal(tag, type, tagIndex);
            return;
        }

        const Modal = window.SRK_Gutenberg.Modal;
        const Button = window.SRK_Gutenberg.components.Button;
        const { useState, useEffect, createElement } = window.SRK_Gutenberg.element;

        const allTags = srkData.templateTags || {};
        const relevantTags = srkData.templateTagsRelevant || {};

        const EditTagModalComponent = () => {
            const [searchTerm, setSearchTerm] = useState('');
            const [filteredTags, setFilteredTags] = useState([]);
            const [isOpen, setIsOpen] = useState(true);

            useEffect(() => {
                if (!allTags) return;

                if (searchTerm.trim() === '') {
                    const relevant = relevantTags[type] || {};
                    const relevantTagValues = Object.values(relevant);

                    const sortedTags = Object.entries(allTags).sort(([nameA, dataA], [nameB, dataB]) => {
                        const isRelevantA = relevantTagValues.includes(dataA.tag);
                        const isRelevantB = relevantTagValues.includes(dataB.tag);

                        if (isRelevantA && !isRelevantB) return -1;
                        if (!isRelevantA && isRelevantB) return 1;
                        return nameA.localeCompare(nameB);
                    });

                    setFilteredTags(sortedTags);
                } else {
                    const filtered = Object.entries(allTags).filter(([name, data]) => {
                        const nameMatch = name.toLowerCase().includes(searchTerm.toLowerCase());
                        const descMatch = data.description.toLowerCase().includes(searchTerm.toLowerCase());
                        return nameMatch || descMatch;
                    });
                    setFilteredTags(filtered);
                }
            }, [searchTerm, allTags, type, relevantTags]);

            const handleSelectTag = (newTag) => {
                console.log('🔄 Replacing tag:', tag, 'with:', newTag);
                replaceCurrentTag(newTag);
                hideEditTagModal();
            };

            const handleDeleteTag = () => {
                console.log('🗑️ Deleting tag:', tag);
                deleteCurrentTag();
                hideEditTagModal();
            };

            const handleClose = () => {
                hideEditTagModal();
            };

            const ModalHeader = () => {
                return createElement('div', {
                    style: {
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'center',
                        padding: '16px 24px',
                        borderBottom: '1px solid #ddd'
                    }
                },
                    createElement('div', {
                        style: {
                            display: 'flex',
                            alignItems: 'center',
                            gap: '15px'
                        }
                    },
                        createElement('h2', {
                            style: {
                                margin: 0,
                                fontSize: '20px',
                                fontWeight: 600,
                                lineHeight: '1.4'
                            }
                        }, srkData.i18n?.replaceDeleteTag || 'Replace or Delete Tag'),

                        createElement(Button, {
                            icon: 'trash',
                            label: srkData.i18n?.deleteTag || 'Delete Tag',
                            onClick: handleDeleteTag,
                            isDestructive: true,
                            style: {
                                padding: '6px',
                                minWidth: '32px',
                                height: '32px',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center'
                            }
                        })
                    )
                );
            };

            if (!isOpen) return null;

            return createElement(Modal, {
                title: '',
                onRequestClose: handleClose,
                className: 'srk-edit-tag-modal',
                style: { maxWidth: '500px' },
                headerActions: ModalHeader()
            },
                createElement('div', { style: { padding: '20px' } },
                    createElement('div', { style: { marginBottom: '20px' } },
                        createElement('input', {
                            type: 'text',
                            className: 'srk-search-input',
                            placeholder: 'Search for replacement...',
                            value: searchTerm,
                            onChange: (e) => setSearchTerm(e.target.value),
                            style: {
                                width: '100%',
                                padding: '8px 12px',
                                border: '1px solid #ddd',
                                borderRadius: '4px',
                                fontSize: '14px'
                            },
                            autoFocus: true
                        })
                    ),

                    createElement('ul', {
                        style: {
                            maxHeight: '300px',
                            overflowY: 'auto',
                            margin: 0,
                            padding: 0,
                            listStyle: 'none',
                            border: '1px solid #eee',
                            borderRadius: '4px'
                        }
                    },
                        filteredTags.map(([name, data]) =>
                            createElement('li', {
                                key: data.tag,
                                className: `srk-tag-item ${tag === data.tag ? 'selected' : ''}`,
                                onClick: () => handleSelectTag(data.tag),
                                style: {
                                    padding: '10px 15px',
                                    borderBottom: '1px solid #eee',
                                    cursor: 'pointer',
                                    display: 'flex',
                                    alignItems: 'flex-start',
                                    backgroundColor: tag === data.tag ? '#f0f9ff' : 'transparent'
                                }
                            },
                                createElement('span', {
                                    style: {
                                        display: 'inline-flex',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                        width: '20px',
                                        height: '20px',
                                        borderRadius: '50%',
                                        background: '#007cba',
                                        color: 'white',
                                        marginRight: '10px',
                                        fontSize: '12px',
                                        fontWeight: 'bold',
                                        flexShrink: 0
                                    }
                                }, '+'),
                                createElement('div', { style: { flex: 1 } },
                                    createElement('h4', {
                                        style: {
                                            margin: '0 0 5px 0',
                                            fontSize: '14px',
                                            fontWeight: 'bold'
                                        }
                                    }, name),
                                    createElement('p', {
                                        style: {
                                            margin: 0,
                                            fontSize: '12px',
                                            color: '#666',
                                            lineHeight: '1.4'
                                        }
                                    }, data.description)
                                )
                            )
                        )
                    ),

                    createElement('div', {
                        style: {
                            marginTop: '20px',
                            padding: '15px',
                            background: '#f8f9fa',
                            border: '1px solid #e0e0e0',
                            borderRadius: '4px'
                        }
                    },
                        createElement('p', {
                            style: {
                                margin: '0 0 10px 0',
                                fontSize: '14px',
                                color: '#666'
                            }
                        },
                            'Current tag:',
                            createElement('strong', {
                                style: {
                                    marginLeft: '5px',
                                    color: '#1d2327'
                                }
                            }, tag.replace(/%/g, '').replace(/_/g, ' '))
                        ),
                        createElement('p', {
                            style: {
                                margin: 0,
                                fontSize: '12px',
                                color: '#646970'
                            }
                        }, 'Click a tag above to replace it, or click Delete to remove it.')
                    ),

                    createElement('div', {
                        style: {
                            display: 'flex',
                            justifyContent: 'flex-end',
                            marginTop: '20px',
                            paddingTop: '20px',
                            borderTop: '1px solid #eee'
                        }
                    },
                        createElement(Button, {
                            isSecondary: true,
                            onClick: handleClose
                        }, srkData.i18n?.cancel || 'Cancel')
                    )
                )
            );
        };

        $('#srk-react-modal-container').show();

        const container = document.getElementById('srk-react-modal-container');
        if (container && window.SRK_Gutenberg.element && window.SRK_Gutenberg.element.render) {
            window.SRK_Gutenberg.element.render(
                createElement(EditTagModalComponent),
                container
            );
        }

        console.log(' Gutenberg Edit/Delete modal shown in Classic Editor');
    }

    /**
     * Hide edit tag modal
     */
    function hideEditTagModal() {
        console.log(' Hiding Gutenberg edit modal');

        const container = document.getElementById('srk-react-modal-container');
        if (container && window.SRK_Gutenberg.element && window.SRK_Gutenberg.element.unmountComponentAtNode) {
            window.SRK_Gutenberg.element.unmountComponentAtNode(container);
            $(container).hide().empty();
        }

        currentEditingTag = null;
        currentEditingTagIndex = -1;
        currentEditingTarget = null;
    }

    /**
     * Fallback: Simple edit modal if Gutenberg not available
     */
    function showSimpleEditTagModal(tag, type, tagIndex) {
        console.log(' Falling back to simple edit modal');

        currentEditingTag = tag;
        currentEditingTagIndex = tagIndex;
        currentEditingTarget = type;

        const modalHtml = `
            <div id="srk-edit-tag-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 100001; display: flex; align-items: center; justify-content: center;">
                <div style="background: white; width: 300px; padding: 20px; border-radius: 8px;">
                    <h3 style="margin-top: 0;">Edit Tag</h3>
                    <p>Tag: <strong>${tag.replace(/%/g, '').replace(/_/g, ' ')}</strong></p>
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="button" id="srk-edit-tag-delete" class="button button-secondary" style="background: #d63638; border-color: #d63638; color: white;">
                            Delete Tag
                        </button>
                        <button type="button" id="srk-edit-tag-cancel" class="button button-secondary">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        `;

        $('body').append(modalHtml);

        $('#srk-edit-tag-delete').on('click', function () {
            deleteCurrentTag();
            $('#srk-edit-tag-modal').remove();
            currentEditingTag = null;
            currentEditingTagIndex = -1;
            currentEditingTarget = null;
        });

        $('#srk-edit-tag-cancel').on('click', function () {
            $('#srk-edit-tag-modal').remove();
            currentEditingTag = null;
            currentEditingTagIndex = -1;
            currentEditingTarget = null;
        });
    }

    /**
     * Insert tag
     */
    function insertTag(tag, type) {
        console.log('➕ Inserting tag:', tag, 'into:', type);

        const hidden = $(`.srk-value-input[data-type="${type}"]`);
        const display = $(`.srk-tag-display[data-type="${type}"]`);

        if (!hidden.length || !display.length) {
            console.error(' Elements not found for insert:', type);
            return;
        }

        const currentValue = hidden.val() || '';
        const newValue = currentValue + (currentValue && !currentValue.endsWith(' ') ? ' ' : '') + tag + ' ';

        hidden.val(newValue);
        $(`#srk_meta_${type === 'description' ? 'description' : 'title'}`).val(newValue);
        renderDisplayFromValue(newValue, type);

        markAsDirty();
        scheduleAutoSave();
        updatePreview();

        console.log(' Tag inserted');
    }

    /**
     * Replace current tag
     */
    function replaceCurrentTag(newTag) {
        if (!currentEditingTarget || currentEditingTagIndex === -1) return;

        const hidden = $(`.srk-value-input[data-type="${currentEditingTarget}"]`);
        const currentValue = hidden.val() || '';
        const segments = parseValueToSegments(currentValue);

        let newValue = '';
        let currentTagIndex = 0;

        for (const segment of segments) {
            if (segment.type === 'tag') {
                if (currentTagIndex === currentEditingTagIndex) {
                    newValue += newTag;
                } else {
                    newValue += segment.value;
                }
                currentTagIndex++;
            } else {
                newValue += segment.value;
            }
        }

        hidden.val(newValue);
        $(`#srk_meta_${currentEditingTarget === 'description' ? 'description' : 'title'}`).val(newValue);
        renderDisplayFromValue(newValue, currentEditingTarget);

        markAsDirty();
        scheduleAutoSave();
        updatePreview();

        console.log(' Tag replaced');
    }

    /**
     * Delete current tag
     */
    function deleteCurrentTag() {
        console.log(' Deleting tag:', currentEditingTag);

        if (!currentEditingTarget || currentEditingTagIndex === -1) {
            console.error('No editing target or index');
            return;
        }

        const hidden = $(`.srk-value-input[data-type="${currentEditingTarget}"]`);
        const currentValue = hidden.val() || '';
        const segments = parseValueToSegments(currentValue);

        let newValue = '';
        let currentTagIndex = 0;

        for (const segment of segments) {
            if (segment.type === 'tag') {
                if (currentTagIndex !== currentEditingTagIndex) {
                    newValue += segment.value;
                }
                currentTagIndex++;
            } else {
                newValue += segment.value;
            }
        }

        newValue = newValue.replace(/\s+/g, ' ').trim();

        hidden.val(newValue);
        $(`#srk_meta_${currentEditingTarget === 'description' ? 'description' : 'title'}`).val(newValue);
        renderDisplayFromValue(newValue, currentEditingTarget);

        markAsDirty();
        scheduleAutoSave();
        updatePreview();

        console.log(' Tag deleted');
    }

    /**
     * Update UI with post data
     */
    function updateUIWithPostData() {
        console.log(' Updating UI with post data...');
        updatePreview();
        console.log(' UI updated with post data');
    }

    /**
     * Update preview
     */
    function updatePreview() {

        const title = $(`.srk-value-input[data-type="title"]`).val() || srkData.defaultTitleTemplate || '%title% %sep% %site_title%';
        const description = $(`.srk-value-input[data-type="description"]`).val() || srkData.defaultDescTemplate || '%excerpt%';

        const titlePreview = processTemplateClassic(title, getPreviewData());
        const descPreview = processTemplateClassic(description, getPreviewData());

        let displayUrl = srkData.siteUrl || 'example.com';
        displayUrl = displayUrl.replace(/^https?:\/\/(www\.)?/, '');

        $('#srk-preview-url').text(displayUrl);
        $('#srk-preview-title').text(titlePreview || srkData.i18n?.noTitle || '(No title)');
        $('#srk-preview-description').text(descPreview || srkData.i18n?.noDescription || '(No description)');

    }

    /**
     * Get preview data
     */
    function getPreviewData() {
        const data = {
            postTitle: currentPostData?.post_title || 'Sample Post Title',
            postExcerpt: currentPostData?.post_excerpt || 'Sample excerpt from a page/post.',
            pageTitle: 'Sample Page Title',
            authorFirstName: srkData.authorFirstName || 'John',
            authorLastName: srkData.authorLastName || 'Doe',
            authorName: srkData.authorName || 'John Doe',
            categories: srkData.categories || 'Uncategorized',
            categoryTitle: srkData.categoryTitle || 'Uncategorized',
            currentDate: srkData.currentDate || new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }),
            currentDay: srkData.currentDay || new Date().getDate().toString(),
            currentMonth: srkData.currentMonth || new Date().toLocaleDateString('en-US', { month: 'long' }),
            currentYear: srkData.currentYear || new Date().getFullYear().toString(),
            customField: srkData.customField || 'Custom Field Value',
            permalink: currentPostData?.permalink || srkData.permalink || (srkData.siteUrl || 'https://example.com') + '/sample-post/',
            postContent: currentPostData?.post_content || srkData.postContent || 'Sample post content text...',
            postDate: currentPostData?.post_date || srkData.postDate || new Date(Date.now() - 7 * 24 * 60 * 60 * 1000).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }),
            postDay: currentPostData?.post_day || srkData.postDay || new Date(Date.now() - 7 * 24 * 60 * 60 * 1000).getDate().toString()
        };

        return data;
    }

    /**
    * Process template for Classic Editor - CORRECTED VERSION
    */
    function processTemplateClassic(template, data) {
        if (!template) return '';

        let processed = template;

        const separator = srkData.separator || '-';
        const siteName = srkData.siteName || '';
        const siteDescription = srkData.siteDescription || '';
        const postType = srkData.postType || 'post';

        // First, replace post-type specific tags
        processed = processed
            .replace(new RegExp(`%title%`, 'g'), data.postTitle || '')
            .replace(new RegExp(`%excerpt%`, 'g'), data.postExcerpt || '');

        // Then replace all other general tags
        processed = processed
            .replace(/%sep%/g, separator)
            .replace(/%site_title%/g, siteName)
            .replace(/%sitedesc%/g, siteDescription)
            .replace(/%title%/g, data.postTitle || '')
            .replace(/%excerpt%/g, data.postExcerpt || '')
            .replace(/%title%/g, data.pageTitle || '')
            .replace(/%author_first_name%/g, data.authorFirstName || '')
            .replace(/%author_last_name%/g, data.authorLastName || '')
            .replace(/%author_name%/g, data.authorName || '')
            .replace(/%categories%/g, data.categories || '')
            .replace(/%term_title%/g, data.categoryTitle || '')
            .replace(/%date%/g, data.currentDate || '')
            .replace(/%day%/g, data.currentDay || '')
            .replace(/%month%/g, data.currentMonth || '')
            .replace(/%year%/g, data.currentYear || '')
            .replace(/%custom_field%/g, data.customField || '')
            .replace(/%permalink%/g, data.permalink || '')
            .replace(/%content%/g, data.postContent || '')
            .replace(/%post_date%/g, data.postDate || '')
            .replace(/%post_day%/g, data.postDay || '');

        return processed;
    }

    /**
  * Save meta data - ONLY when Save button is clicked
  */
    function saveMetaData(silent = false) {
        if (isSaving) {
            return Promise.resolve();
        }

        isSaving = true;
        $('#srk-saving-text').show();
        $('#srk-saved-text').hide();
        $('#srk-save').prop('disabled', true).text(srkData.i18n?.saving || 'Saving...');

        if (!silent) {
            showSaveStatus(srkData.i18n?.saving || 'Saving SEO settings...', 'saving');
        }

        const titleValue = $(`.srk-value-input[data-type="title"]`).val();
        const descValue = $(`.srk-value-input[data-type="description"]`).val();
        const canonicalValue = $('#srk-canonical-input').val();

        // Save to hidden fields
        $(`#srk_meta_title`).val(titleValue);
        $(`#srk_meta_description`).val(descValue);
        $(`#srk_canonical_url`).val(canonicalValue);
        $(`#srk_advanced_settings`).val(JSON.stringify(advancedSettings));

        const saveData = {
            action: 'srk_save_meta_data',
            post_id: srkData.postId,
            meta_title: titleValue,
            meta_description: descValue,
            canonical_url: canonicalValue,
            advanced_settings: advancedSettings,
            nonce: srkData.nonce
        };

        return new Promise((resolve, reject) => {
            $.ajax({
                url: srkData.ajaxUrl,
                type: 'POST',
                data: saveData,
                success: function (response) {
                    if (response.success) {
                        lastSyncTime = response.data.last_sync;

                        if (!silent) {
                            $('#srk-saving-text').hide();
                            $('#srk-saved-text').show().fadeIn();
                            setTimeout(function () {
                                $('#srk-saved-text').fadeOut();
                            }, 2000);

                            showSaveStatus(srkData.i18n?.saved || 'SEO settings saved successfully!', 'success');
                            showSyncNotification('✅ ' + (srkData.i18n?.saved || 'SEO settings saved successfully!'));
                        }

                        resolve(response);
                    } else {
                        if (!silent) {
                            showSaveStatus(srkData.i18n?.error || 'Error saving settings', 'error');
                            showSyncNotification('❌ ' + (srkData.i18n?.error || 'Error saving settings'), true);
                        }
                        reject(response);
                    }
                },
                error: function (xhr, status, error) {
                    console.error('❌ Save error:', error);
                    if (!silent) {
                        showSaveStatus(srkData.i18n?.error + ': ' + error || 'Save failed: ' + error, 'error');
                        showSyncNotification('❌ ' + (srkData.i18n?.error || 'Save failed: ') + error, true);
                    }
                    reject(error);
                },
                complete: function () {
                    isSaving = false;
                    $('#srk-save').prop('disabled', false).text(srkData.i18n?.saveChanges || 'Save Changes');
                }
            });
        });
    }

    /**
     * Remove ALL auto-save triggers - these functions should do nothing
     */
    function markAsDirty() {
        // DO NOTHING - Auto-save disabled
    }

    function scheduleAutoSave() {
        // DO NOTHING - Auto-save disabled completely
    }
    /**
     * Integrate with WordPress form submission
     */
    function integrateWithWordPressForm() {
        $(document).on('click', '#publish, #save-post, #post #save-action input[type="submit"]', function (e) {
            saveMetaData(true);

            const titleValue = $(`.srk-value-input[data-type="title"]`).val();
            const descValue = $(`.srk-value-input[data-type="description"]`).val();
            const canonicalValue = $('#srk-canonical-input').val();

            $(`#srk_meta_title`).val(titleValue);
            $(`#srk_meta_description`).val(descValue);
            $(`#srk_canonical_url`).val(canonicalValue);
            $(`#srk_advanced_settings`).val(JSON.stringify(advancedSettings));
            $(`#srk_last_sync`).val(Math.floor(Date.now() / 1000));
        });

        $('#post').on('submit', function (e) {
            saveMetaData(true);
        });

        $(window).on('beforeunload', function () {
            if (hasChanges()) {
                saveMetaData(true);
            }
        });

    }

    /**
     * Reset to default template
     */
    function resetToDefaultTemplate(type) {
        const defaultTemplate = type === 'title' ?
            srkData.defaultTitleTemplate || '%title% %sep% %site_title%' :
            srkData.defaultDescTemplate || '%excerpt%';

        $(`.srk-value-input[data-type="${type}"]`).val(defaultTemplate);
        $(`#srk_meta_${type === 'description' ? 'description' : 'title'}`).val(defaultTemplate);
        renderDisplayFromValue(defaultTemplate, type);

        markAsDirty();
        scheduleAutoSave();
        updatePreview();
    }

    /**
 * Reset all to defaults - TRUE RESET VERSION for Classic Editor
 * Deletes post meta and enters Follow Mode
 */
    /**
     * Reset all to defaults - TRUE RESET VERSION for Classic Editor
     * Deletes post meta and enters Follow Mode
     */
    function resetAllToDefaults() {
        if (!confirm('Reset to Content Type defaults? This will delete all custom SEO settings and follow global Content Type settings dynamically.')) {
            return;
        }

        showSaveStatus('Resetting to Content Type defaults...', 'saving');

        // Call AJAX to delete meta and set follow mode
        $.ajax({
            url: srkData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'srk_reset_to_content_type',
                post_id: srkData.postId,
                nonce: srkData.nonce
            },
            success: function (response) {
                if (response.success) {
                    // Get Content Type settings
                    const contentTypeSettings = srkData.contentTypeSettings || {};
                    const contentTypeRobots = srkData.contentTypeRobots || {};

                    const defaultTitle = contentTypeSettings.title || srkData.defaultTitleTemplate || '%title% %sep% %site_title%';
                    const defaultDesc = contentTypeSettings.desc || srkData.defaultDescTemplate || '%excerpt%';

                    // Update UI fields
                    $(`.srk-value-input[data-type="title"]`).val(defaultTitle);
                    $(`.srk-value-input[data-type="description"]`).val(defaultDesc);
                    $('#srk-canonical-input').val('');

                    // Update hidden fields - EMPTY for follow mode
                    $('#srk_meta_title').val('');
                    $('#srk_meta_description').val('');
                    $('#srk_canonical_url').val('');

                    // Set Follow Mode settings
                    const followModeSettings = {
                        use_default_settings: '1',
                        follow_mode: '1',
                        show_meta_box: '1',
                        robots_meta: contentTypeRobots
                    };

                    advancedSettings = followModeSettings;
                    $('#srk_advanced_settings').val(JSON.stringify(followModeSettings));

                    // Update displays
                    renderDisplayFromValue(defaultTitle, 'title');
                    renderDisplayFromValue(defaultDesc, 'description');
                    updateAdvancedSettingsUI();
                    updatePreview();

                    showSaveStatus('Reset successful - Following Content Type dynamically', 'success');
                    showSyncNotification('✅ Reset to Content Type defaults - Dynamic follow mode active');

                } else {
                    showSaveStatus('Reset failed', 'error');
                }
                // Set new original values (disables save button)
                const contentTypeSettings = srkData.contentTypeSettings || {};
                const defaultTitle = contentTypeSettings.title || srkData.defaultTitleTemplate || '%title% %sep% %site_title%';
                const defaultDesc = contentTypeSettings.desc || srkData.defaultDescTemplate || '%excerpt%';

                originalValuesClassic = {
                    title: defaultTitle,
                    description: defaultDesc,
                    canonical: '',
                    advanced: {
                        use_default_settings: '1',
                        follow_mode: '1',
                        show_meta_box: '1'
                    }
                };
                hasFormChangesClassic = false;
                $('#srk-save').prop('disabled', true).css('opacity', '0.5');
            },
            error: function (xhr, status, error) {
                console.error('❌ Reset error:', error);
                showSaveStatus('Reset error: ' + error, 'error');
            }
        });
    }
    // Toggle click handler - WITHOUT CHECKBOX
    $(document).off('click', '#srk-toggle-wrapper').on('click', '#srk-toggle-wrapper', function (e) {
        e.preventDefault();

        const $toggle = $(this);
        const $knob = $('#srk-toggle-knob');
        const isCurrentlyActive = $toggle.hasClass('active');

        // Toggle state
        const newState = !isCurrentlyActive;

        // Update visual state
        if (newState) {
            // Turn ON (Blue, knob right)
            $toggle.addClass('active').css('background', '#007cba');
            $knob.css('left', '30px');
            advancedSettings.use_default_settings = '1';
            $('.srk-robots-settings-container').slideUp(200);
        } else {
            // Turn OFF (Gray, knob left)
            $toggle.removeClass('active').css('background', '#ccc');
            $knob.css('left', '4px');
            advancedSettings.use_default_settings = '0';
            $('.srk-robots-settings-container').slideDown(200);
        }

        // Save to hidden field
        $('#srk_advanced_settings').val(JSON.stringify(advancedSettings));

        markAsDirty();
        scheduleAutoSave();
    });

    /**
     * Helper functions
     */
    function tagToDisplay(tag) {
        if (!tag) return '';
        // Remove % signs and replace _ with spaces first
        let displayText = tag.replace(/%/g, '').replace(/_/g, ' ');
        // Remove "srk " (with space) after underscore replacement
        displayText = displayText.replace(/^srk /i, '').replace(/ srk /gi, ' ');
        return displayText;
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

})(jQuery);
