/**
 * FAQ Schema for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 * @since       2.1.0
 */
(function($){
    class FaqSchema extends window.SRK.BaseSchema {
        constructor(manager){
            super(manager);
            this.schemaKey = "faq";
        }
 
        handleSelection() {
            const container = $('#srk-schema-config-wrapper');
            container.html(`
                <div class="srk-schema-config-card">
                    <h3>FAQ Configuration</h3>
                   
                    <!-- ✅ CONCISE ASSIGNMENT MESSAGE -->
                    <div class="notice notice-info" style="margin: 10px 0; padding: 8px 12px;">
                        <p>❓ <strong>Google Recommended:</strong> Assign to <strong>Pages</strong> with FAQ content</p>
                    </div>
     
                    <!-- Post Type Dropdown -->
                    <div class="srk-form-group">
                        <label for="assign-${this.schemaKey}">Assign to Post Type:</label>
                        <select id="assign-${this.schemaKey}" class="srk-form-select">
                            <option value="">Select Post Type</option>
                        </select>
                    </div>
     
                    <!-- Post/Page Dropdown -->
                    <div class="srk-form-group" id="faq-item-wrapper" style="display:none;">
                        <label for="faq-item-select">Select Item (Post/Page):</label>
                        <select id="faq-item-select" class="srk-form-select">
                            <option value="">Select Item</option>
                        </select>
                    </div>
     
                    <!-- Repeater -->
                    <div class="srk-faq-repeater">
                        <h4>FAQ Questions & Answers</h4>
                        <div id="faq-items"></div>
                        <button type="button" id="add-faq" class="button">+ Add FAQ</button>
                    </div>
                </div>
            `);
     
            // Load Post Types
            $.post(srk_ajax_object.ajax_url, { action: 'srk_get_post_types' }, (res) => {
                if(res.success){
                    $(`#assign-${this.schemaKey}`).append(res.data);
                   
                    // Check if we have saved configuration and auto-load
                    this.checkAndLoadSavedConfiguration();
                }
            });
     
            this.bindRepeaterEvents();
        }
        
        // NEW METHOD: Check and load saved configuration
        checkAndLoadSavedConfiguration() {
            $.post(srk_ajax_object.ajax_url, {
                action: 'srk_get_faq_configuration'
            }, (response) => {
                if (response.success && response.data.configured) {
                    const config = response.data;
                   
                    // Auto-select the post type
                    if (config.post_type) {
                        $(`#assign-${this.schemaKey}`).val(config.post_type);
                       
                        // Load posts for this post type first using the shared srk_get_posts_by_type endpoint
                        $.post(srk_ajax_object.ajax_url, {
                            action: 'srk_get_posts_by_type',
                            post_type: config.post_type
                        }, (postsResponse) => {
                            if (postsResponse.success && postsResponse.data) {
                                // postsResponse.data is an object map: { ID: title }
                                const options = ['<option value=\"\">Select Item</option>'];
                                $.each(postsResponse.data, (id, title) => {
                                    options.push(`<option value=\"${id}\">${this.escapeHtml(title)}</option>`);
                                });

                                // Show and populate the post selector
                                $('#faq-item-wrapper').show();
                                $('#faq-item-select').html(options.join(''));
                               
                                // Select the saved post
                                if (config.post_id) {
                                    $('#faq-item-select').val(config.post_id);
                                }
                               
                                // Load FAQ items
                                if (config.faq_items && config.faq_items.length > 0) {
                                    this.loadFaqItems(config.faq_items);
                                }
                               
                                this.generatePreview();
                            }
                        });
                    }
                }
            });
        }
       
        // NEW METHOD: Load FAQ items from saved configuration
        loadFaqItems(faqItems) {
            $('#faq-items').empty();
            
            faqItems.forEach((item, index) => {
                $('#faq-items').append(`
                    <div class="faq-item">
                        <div class="faq-row">
                            <label>Question:</label><br>
                            <input type="text" class="faq-question srk-group-values" 
                                placeholder="Enter Question" data-field="question_${index}" 
                                value="${this.escapeHtml(item.question)}" style="width:100%;">
                        </div>
                        <div class="faq-row">
                            <label>Answer:</label><br>
                            <textarea class="faq-answer srk-group-values" 
                                placeholder="Enter Answer" data-field="answer_${index}" 
                                style="width:100%; height:80px;">${this.escapeHtml(item.answer)}</textarea>
                        </div>
                        <button type="button" class="remove-faq button">Remove</button>
                        <hr>
                    </div>
                `);
            });
            
            this.generatePreview();
        }
        
        // NEW METHOD: Escape HTML for safe display
        escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }
 
        bindRepeaterEvents(){
            // Load posts when post type changes
            $(document).on('change', `#assign-${this.schemaKey}`, (e) => {
                const postType = e.target.value;
                if (!postType) {
                    $('#faq-item-wrapper').hide();
                    return;
                }
 
                $('#faq-item-select').html('<option value=\"\">⏳ Loading posts...</option>');
                
                $.post(srk_ajax_object.ajax_url, {
                    action: 'srk_get_posts_by_type',
                    post_type: postType
                }, (res) => {
                    if(res.success && res.data){
                        const options = ['<option value=\"\">Select Item</option>'];
                        $.each(res.data, (id, title) => {
                            options.push(`<option value=\"${id}\">${this.escapeHtml(title)}</option>`);
                        });
                        $('#faq-item-wrapper').show();
                        $('#faq-item-select').html(options.join(''));
                        this.generatePreview();
                    }
                });
            });
            
            // Update preview when post selection changes
            $(document).on('change', '#faq-item-select', () => {
                this.generatePreview();
            });

            // Add new FAQ item
            $(document).on('click', '#add-faq', () => {
                const index = $('#faq-items .faq-item').length;
                $('#faq-items').append(`
                    <div class="faq-item">
                        <div class="faq-row">
                            <label>Question:</label><br>
                            <input type="text" class="faq-question srk-group-values" placeholder="Enter Question" data-field="question_${index}" style="width:100%;">
                        </div>
                        <div class="faq-row">
                            <label>Answer:</label><br>
                            <textarea class="faq-answer srk-group-values" placeholder="Enter Answer" data-field="answer_${index}" style="width:100%; height:80px;"></textarea>
                        </div>
                        <button type="button" class="remove-faq button">Remove</button>
                        <hr>
                    </div>
                `);
                this.generatePreview();
            });
 
            // Remove FAQ item
            $(document).on('click', '.remove-faq', (e) => {
                $(e.target).closest('.faq-item').remove();
                this.generatePreview();
            });
 
            // Live update on input changes
            $(document).on('input', '.faq-question, .faq-answer', () => {
                this.generatePreview();
            });
        }
 
        getConfigData(){
            const data = {
                post_type: $(`#assign-${this.schemaKey}`).val(),
                post_id: $('#faq-item-select').val(),
                faq_items: []
            };
 
            $('#faq-items .faq-item').each(function(){
                const question = $(this).find('.faq-question').val();
                const answer   = $(this).find('.faq-answer').val();
 
                if(question && answer){
                    data.faq_items.push({
                        question: question,
                        answer: answer
                    });
                }
            });
 
            return data;
        }
 
        generatePreview(){
            const jsonData = {
                "@context": "https://schema.org",
                "@type": "FAQPage",
                "post_type": $(`#assign-${this.schemaKey}`).val(),
                "post_id": $('#faq-item-select').val(),
                "faq_items": [],
                "mainEntity": []
            };
 
            $('#faq-items .faq-item').each(function(){
                const question = $(this).find('.faq-question').val();
                const answer = $(this).find('.faq-answer').val();
 
                if(question && answer){
                    const item = { "question": question, "answer": answer };
                    jsonData.faq_items.push(item);
 
                    jsonData.mainEntity.push({
                        "@type": "Question",
                        "name": question,
                        "acceptedAnswer": {
                            "@type": "Answer",
                            "text": answer
                        }
                    });
                }
            });
 
            // ✅ NEW: Hide loader and show preview when ready
            $('#srk-json-preview-loader').hide();
            $('#srk-json-preview').text(JSON.stringify(jsonData, null, 2)).show();
            $('#srk-json-preview-container').show();
        }
    }
 
    window.SRK.FaqSchema = FaqSchema;
})(jQuery);