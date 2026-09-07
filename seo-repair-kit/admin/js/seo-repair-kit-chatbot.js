import { createChat } from 'https://cdn.jsdelivr.net/npm/@n8n/chat/dist/chat.bundle.es.js';

/**
 * SEO Repair Kit - AI Chatbot JavaScript
 *
 * @since 2.1.0
 */
(function () {
  const cfg = (typeof window !== 'undefined' && window.srkChatbot) ? window.srkChatbot : {};

  const webhookUrl = (cfg && typeof cfg.webhookUrl === 'string') ? cfg.webhookUrl.trim() : '';
  const target     = (cfg && typeof cfg.target === 'string') ? cfg.target : '#n8n-chat';
  const mode       = (cfg && typeof cfg.mode === 'string') ? cfg.mode : 'fullscreen';
  const showWelcomeScreen = !!(cfg && cfg.showWelcome);
  const initialMessages   = (cfg && Array.isArray(cfg.initialMessages)) ? cfg.initialMessages : ['Hi there! 👋'];

  const i18n = (cfg && cfg.i18n && cfg.i18n.en) ? cfg.i18n : {
    en: {
      title: 'Hello! 👋',
      subtitle: 'I can help you with SEO, plugins, and more.',
      getStarted: 'Start a Chat',
      inputPlaceholder: 'Ask something...',
    }
  };

  if (!webhookUrl) {
    console.warn('[SRK Chatbot] Missing relay URL.');
    return;
  }

  try {
    createChat({
      webhookUrl,
      mode,
      target,
      showWelcomeScreen,
      initialMessages,
      i18n,
    });
  } catch (e) {
    console.error('[SRK Chatbot] Failed to initialize chat:', e);
  }
})();
