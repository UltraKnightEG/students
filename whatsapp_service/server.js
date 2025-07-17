/**
 * خدمة الواتساب المتقدمة لنظام إدارة حضور الطلاب
 * Advanced WhatsApp Service for Student Attendance System
 * 
 * الميزات:
 * - إدارة جلسات الواتساب المتعددة
 * - إرسال الرسائل الجماعية والفردية
 * - إدارة QR Code للاتصال
 * - تسجيل شامل للأنشطة
 * - API متكامل مع النظام الرئيسي
 * - إدارة الأخطاء والاستثناءات
 * - دعم الرسائل المجدولة
 */

const express = require('express');
const cors = require('cors');
const bodyParser = require('body-parser');
const helmet = require('helmet');
const morgan = require('morgan');
const fs = require('fs-extra');
const path = require('path');
const moment = require('moment-timezone');
const QRCode = require('qrcode');
const winston = require('winston');
const cron = require('node-cron');
const axios = require('axios');
const venom = require('venom-bot');

// إعداد المنطقة الزمنية
moment.tz.setDefault('Asia/Riyadh');

// إعداد التطبيق
const app = express();
const PORT = process.env.PORT || 3000;
const HOST = process.env.HOST || '0.0.0.0';

// إعداد المجلدات
const SESSIONS_DIR = path.join(__dirname, 'sessions');
const LOGS_DIR = path.join(__dirname, 'logs');
const TEMP_DIR = path.join(__dirname, 'temp');

// إنشاء المجلدات المطلوبة
fs.ensureDirSync(SESSIONS_DIR);
fs.ensureDirSync(LOGS_DIR);
fs.ensureDirSync(TEMP_DIR);

// إعداد نظام التسجيل
const logger = winston.createLogger({
    level: 'info',
    format: winston.format.combine(
        winston.format.timestamp({
            format: 'YYYY-MM-DD HH:mm:ss'
        }),
        winston.format.errors({ stack: true }),
        winston.format.json()
    ),
    defaultMeta: { service: 'whatsapp-service' },
    transports: [
        new winston.transports.File({ 
            filename: path.join(LOGS_DIR, 'error.log'), 
            level: 'error' 
        }),
        new winston.transports.File({ 
            filename: path.join(LOGS_DIR, 'combined.log') 
        }),
        new winston.transports.Console({
            format: winston.format.combine(
                winston.format.colorize(),
                winston.format.simple()
            )
        })
    ]
});

// متغيرات النظام
let whatsappClient = null;
let isConnected = false;
let qrCode = null;
let connectionStatus = 'disconnected';
let messageQueue = [];
let processingQueue = false;

// إعداد Express
app.use(helmet());
app.use(cors({
    origin: '*',
    methods: ['GET', 'POST', 'PUT', 'DELETE'],
    allowedHeaders: ['Content-Type', 'Authorization']
}));
app.use(bodyParser.json({ limit: '10mb' }));
app.use(bodyParser.urlencoded({ extended: true, limit: '10mb' }));
app.use(morgan('combined', { stream: { write: message => logger.info(message.trim()) } }));

// إعداد ملفات ثابتة
app.use('/static', express.static(path.join(__dirname, 'public')));

/**
 * فئة إدارة الواتساب
 */
class WhatsAppManager {
    constructor() {
        this.client = null;
        this.isReady = false;
        this.sessionName = 'attendance-system';
        this.retryCount = 0;
        this.maxRetries = 3;
        this.messageHistory = [];
        this.scheduledMessages = [];
    }

    /**
     * بدء جلسة الواتساب
     */
    async startSession() {
        try {
            logger.info('بدء تشغيل جلسة الواتساب...');
            connectionStatus = 'connecting';

            this.client = await venom.create({
                session: this.sessionName,
                multidevice: true,
                folderNameToken: SESSIONS_DIR,
                headless: true,
                devtools: false,
                useChrome: true,
                debug: false,
                logQR: false,
                browserWS: '',
                browserArgs: [
                    '--no-sandbox',
                    '--disable-setuid-sandbox',
                    '--disable-dev-shm-usage',
                    '--disable-accelerated-2d-canvas',
                    '--no-first-run',
                    '--no-zygote',
                    '--single-process',
                    '--disable-gpu'
                ],
                refreshQR: 15000,
                autoClose: 60000,
                disableSpins: true,
                disableWelcome: true,
                updatesLog: false,
                createPathFileToken: true
            }, 
            // QR Code callback
            (base64Qr, asciiQR, attempts, urlCode) => {
                this.handleQRCode(base64Qr, asciiQR, attempts, urlCode);
            },
            // Status callback
            (statusSession, session) => {
                this.handleStatusChange(statusSession, session);
            });

            // إعداد معالجات الأحداث
            this.setupEventHandlers();
            
            logger.info('تم تشغيل جلسة الواتساب بنجاح');
            return true;

        } catch (error) {
            logger.error('خطأ في تشغيل جلسة الواتساب:', error);
            connectionStatus = 'error';
            
            if (this.retryCount < this.maxRetries) {
                this.retryCount++;
                logger.info(`محاولة إعادة الاتصال ${this.retryCount}/${this.maxRetries}...`);
                setTimeout(() => this.startSession(), 5000);
            }
            
            return false;
        }
    }

    /**
     * معالجة QR Code
     */
    handleQRCode(base64Qr, asciiQR, attempts, urlCode) {
        logger.info(`QR Code جديد - المحاولة ${attempts}`);
        qrCode = base64Qr;
        connectionStatus = 'qr_ready';
        
        // حفظ QR Code كملف
        const qrPath = path.join(TEMP_DIR, 'qr-code.png');
        QRCode.toFile(qrPath, urlCode, (err) => {
            if (err) {
                logger.error('خطأ في حفظ QR Code:', err);
            } else {
                logger.info('تم حفظ QR Code في:', qrPath);
            }
        });
    }

    /**
     * معالجة تغيير حالة الاتصال
     */
    handleStatusChange(statusSession, session) {
        logger.info(`تغيير حالة الجلسة: ${statusSession}`);
        
        switch (statusSession) {
            case 'successChat':
                connectionStatus = 'connected';
                isConnected = true;
                this.isReady = true;
                qrCode = null;
                this.retryCount = 0;
                logger.info('تم الاتصال بالواتساب بنجاح');
                this.startMessageProcessor();
                break;
                
            case 'qrReadSuccess':
                connectionStatus = 'authenticating';
                logger.info('تم مسح QR Code بنجاح، جاري المصادقة...');
                break;
                
            case 'autocloseCalled':
            case 'desconnectedMobile':
                connectionStatus = 'disconnected';
                isConnected = false;
                this.isReady = false;
                logger.warn('تم قطع الاتصال مع الواتساب');
                break;
                
            case 'serverClose':
                connectionStatus = 'error';
                isConnected = false;
                this.isReady = false;
                logger.error('خطأ في الخادم');
                break;
        }
    }

    /**
     * إعداد معالجات الأحداث
     */
    setupEventHandlers() {
        if (!this.client) return;

        // معالج الرسائل الواردة
        this.client.onMessage((message) => {
            this.handleIncomingMessage(message);
        });

        // معالج حالة الرسائل
        this.client.onAck((ack) => {
            this.handleMessageAck(ack);
        });

        // معالج الأخطاء
        this.client.onStateChange((state) => {
            logger.info(`تغيير حالة الواتساب: ${state}`);
        });
    }

    /**
     * معالجة الرسائل الواردة
     */
    handleIncomingMessage(message) {
        logger.info('رسالة واردة:', {
            from: message.from,
            body: message.body,
            type: message.type
        });

        // حفظ الرسالة في السجل
        this.messageHistory.push({
            id: message.id,
            from: message.from,
            to: message.to,
            body: message.body,
            type: message.type,
            timestamp: moment().format(),
            direction: 'incoming'
        });

        // معالجة الردود التلقائية إذا لزم الأمر
        this.handleAutoReply(message);
    }

    /**
     * معالجة الردود التلقائية
     */
    async handleAutoReply(message) {
        // يمكن إضافة منطق الردود التلقائية هنا
        // مثل الرد على كلمات مفتاحية معينة
        
        const body = message.body.toLowerCase().trim();
        
        if (body === 'مساعدة' || body === 'help') {
            await this.sendMessage(message.from, 
                'مرحباً بك في نظام إدارة حضور الطلاب 📚\n\n' +
                'للحصول على معلومات حول حضور طفلك، يرجى انتظار التقارير اليومية.\n\n' +
                'شكراً لك 🌟'
            );
        }
    }

    /**
     * معالجة تأكيد الرسائل
     */
    handleMessageAck(ack) {
        logger.info('تأكيد الرسالة:', {
            id: ack.id,
            ack: ack.ack,
            from: ack.from
        });

        // تحديث حالة الرسالة في السجل
        const messageIndex = this.messageHistory.findIndex(msg => msg.id === ack.id);
        if (messageIndex !== -1) {
            this.messageHistory[messageIndex].ack = ack.ack;
            this.messageHistory[messageIndex].ackTime = moment().format();
        }
    }

    /**
     * إرسال رسالة
     */
    async sendMessage(to, message, options = {}) {
        if (!this.isReady || !this.client) {
            throw new Error('الواتساب غير متصل');
        }

        try {
            // تنسيق رقم الهاتف
            const formattedNumber = this.formatPhoneNumber(to);
            
            // إرسال الرسالة
            const result = await this.client.sendText(formattedNumber, message);
            
            logger.info('تم إرسال الرسالة بنجاح:', {
                to: formattedNumber,
                messageId: result.id
            });

            // حفظ الرسالة في السجل
            this.messageHistory.push({
                id: result.id,
                from: 'system',
                to: formattedNumber,
                body: message,
                type: 'text',
                timestamp: moment().format(),
                direction: 'outgoing',
                status: 'sent'
            });

            return result;

        } catch (error) {
            logger.error('خطأ في إرسال الرسالة:', error);
            throw error;
        }
    }

    /**
     * إرسال رسالة مع صورة
     */
    async sendImageMessage(to, imagePath, caption = '') {
        if (!this.isReady || !this.client) {
            throw new Error('الواتساب غير متصل');
        }

        try {
            const formattedNumber = this.formatPhoneNumber(to);
            const result = await this.client.sendImage(formattedNumber, imagePath, 'image', caption);
            
            logger.info('تم إرسال الصورة بنجاح:', {
                to: formattedNumber,
                messageId: result.id
            });

            return result;

        } catch (error) {
            logger.error('خطأ في إرسال الصورة:', error);
            throw error;
        }
    }

    /**
     * إرسال رسائل جماعية
     */
    async sendBulkMessages(recipients, message, options = {}) {
        const results = [];
        const delay = options.delay || 2000; // تأخير بين الرسائل

        for (const recipient of recipients) {
            try {
                const result = await this.sendMessage(recipient.phone, message);
                results.push({
                    recipient: recipient,
                    success: true,
                    messageId: result.id
                });

                // تأخير بين الرسائل لتجنب الحظر
                if (delay > 0) {
                    await this.sleep(delay);
                }

            } catch (error) {
                logger.error(`خطأ في إرسال رسالة إلى ${recipient.phone}:`, error);
                results.push({
                    recipient: recipient,
                    success: false,
                    error: error.message
                });
            }
        }

        return results;
    }

    /**
     * تنسيق رقم الهاتف
     */
    formatPhoneNumber(phone) {
        // إزالة الرموز والمسافات
        let formatted = phone.replace(/[^\d]/g, '');
        
        // إضافة رمز الدولة السعودية إذا لم يكن موجوداً
        if (formatted.startsWith('05')) {
            formatted = '966' + formatted.substring(1);
        } else if (formatted.startsWith('5')) {
            formatted = '966' + formatted;
        } else if (!formatted.startsWith('966')) {
            formatted = '966' + formatted;
        }
        
        return formatted + '@c.us';
    }

    /**
     * التحقق من صحة رقم الهاتف
     */
    async isValidNumber(phone) {
        if (!this.isReady || !this.client) {
            return false;
        }

        try {
            const formattedNumber = this.formatPhoneNumber(phone);
            const result = await this.client.checkNumberStatus(formattedNumber);
            return result.exists;
        } catch (error) {
            logger.error('خطأ في التحقق من رقم الهاتف:', error);
            return false;
        }
    }

    /**
     * بدء معالج الرسائل
     */
    startMessageProcessor() {
        if (processingQueue) return;
        
        processingQueue = true;
        this.processMessageQueue();
    }

    /**
     * معالجة قائمة انتظار الرسائل
     */
    async processMessageQueue() {
        while (messageQueue.length > 0 && this.isReady) {
            const messageData = messageQueue.shift();
            
            try {
                await this.sendMessage(messageData.to, messageData.message, messageData.options);
                logger.info('تم إرسال رسالة من قائمة الانتظار');
            } catch (error) {
                logger.error('خطأ في إرسال رسالة من قائمة الانتظار:', error);
            }
            
            // تأخير بين الرسائل
            await this.sleep(1000);
        }
        
        processingQueue = false;
    }

    /**
     * إضافة رسالة إلى قائمة الانتظار
     */
    queueMessage(to, message, options = {}) {
        messageQueue.push({ to, message, options });
        
        if (this.isReady && !processingQueue) {
            this.startMessageProcessor();
        }
    }

    /**
     * الحصول على معلومات الجلسة
     */
    getSessionInfo() {
        return {
            isConnected: this.isReady,
            connectionStatus: connectionStatus,
            qrCode: qrCode,
            messageCount: this.messageHistory.length,
            queueLength: messageQueue.length,
            sessionName: this.sessionName
        };
    }

    /**
     * إنهاء الجلسة
     */
    async closeSession() {
        try {
            if (this.client) {
                await this.client.close();
                logger.info('تم إنهاء جلسة الواتساب');
            }
            
            this.isReady = false;
            isConnected = false;
            connectionStatus = 'disconnected';
            
        } catch (error) {
            logger.error('خطأ في إنهاء الجلسة:', error);
        }
    }

    /**
     * دالة مساعدة للتأخير
     */
    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
}

// إنشاء مدير الواتساب
const whatsappManager = new WhatsAppManager();

/**
 * API Routes
 */

// الصفحة الرئيسية
app.get('/', (req, res) => {
    res.json({
        service: 'خدمة الواتساب لنظام إدارة الحضور',
        version: '2.0.0',
        status: connectionStatus,
        timestamp: moment().format()
    });
});

// بدء الجلسة
app.post('/api/start', async (req, res) => {
    try {
        logger.info('طلب بدء جلسة الواتساب');
        
        if (isConnected) {
            return res.json({
                success: true,
                message: 'الواتساب متصل بالفعل',
                data: whatsappManager.getSessionInfo()
            });
        }

        const result = await whatsappManager.startSession();
        
        res.json({
            success: result,
            message: result ? 'تم بدء الجلسة بنجاح' : 'فشل في بدء الجلسة',
            data: whatsappManager.getSessionInfo()
        });

    } catch (error) {
        logger.error('خطأ في بدء الجلسة:', error);
        res.status(500).json({
            success: false,
            message: 'خطأ في بدء الجلسة',
            error: error.message
        });
    }
});

// إيقاف الجلسة
app.post('/api/stop', async (req, res) => {
    try {
        await whatsappManager.closeSession();
        
        res.json({
            success: true,
            message: 'تم إيقاف الجلسة بنجاح'
        });

    } catch (error) {
        logger.error('خطأ في إيقاف الجلسة:', error);
        res.status(500).json({
            success: false,
            message: 'خطأ في إيقاف الجلسة',
            error: error.message
        });
    }
});

// حالة الجلسة
app.get('/api/status', (req, res) => {
    res.json({
        success: true,
        data: whatsappManager.getSessionInfo()
    });
});

// الحصول على QR Code
app.get('/api/qr', (req, res) => {
    if (qrCode) {
        res.json({
            success: true,
            data: {
                qrCode: qrCode,
                status: connectionStatus
            }
        });
    } else {
        res.json({
            success: false,
            message: 'QR Code غير متوفر',
            data: {
                status: connectionStatus
            }
        });
    }
});

// إرسال رسالة فردية
app.post('/api/send', async (req, res) => {
    try {
        const { to, message, type = 'text' } = req.body;

        if (!to || !message) {
            return res.status(400).json({
                success: false,
                message: 'رقم الهاتف والرسالة مطلوبان'
            });
        }

        if (!isConnected) {
            return res.status(503).json({
                success: false,
                message: 'الواتساب غير متصل'
            });
        }

        const result = await whatsappManager.sendMessage(to, message);

        res.json({
            success: true,
            message: 'تم إرسال الرسالة بنجاح',
            data: {
                messageId: result.id,
                to: to
            }
        });

    } catch (error) {
        logger.error('خطأ في إرسال الرسالة:', error);
        res.status(500).json({
            success: false,
            message: 'خطأ في إرسال الرسالة',
            error: error.message
        });
    }
});

// إرسال رسائل جماعية
app.post('/api/send-bulk', async (req, res) => {
    try {
        const { recipients, message, options = {} } = req.body;

        if (!recipients || !Array.isArray(recipients) || recipients.length === 0) {
            return res.status(400).json({
                success: false,
                message: 'قائمة المستقبلين مطلوبة'
            });
        }

        if (!message) {
            return res.status(400).json({
                success: false,
                message: 'نص الرسالة مطلوب'
            });
        }

        if (!isConnected) {
            return res.status(503).json({
                success: false,
                message: 'الواتساب غير متصل'
            });
        }

        const results = await whatsappManager.sendBulkMessages(recipients, message, options);

        const successCount = results.filter(r => r.success).length;
        const failureCount = results.filter(r => !r.success).length;

        res.json({
            success: true,
            message: `تم إرسال ${successCount} رسالة بنجاح، فشل في ${failureCount} رسالة`,
            data: {
                total: recipients.length,
                success: successCount,
                failed: failureCount,
                results: results
            }
        });

    } catch (error) {
        logger.error('خطأ في إرسال الرسائل الجماعية:', error);
        res.status(500).json({
            success: false,
            message: 'خطأ في إرسال الرسائل الجماعية',
            error: error.message
        });
    }
});

// التحقق من صحة رقم الهاتف
app.post('/api/validate-number', async (req, res) => {
    try {
        const { phone } = req.body;

        if (!phone) {
            return res.status(400).json({
                success: false,
                message: 'رقم الهاتف مطلوب'
            });
        }

        if (!isConnected) {
            return res.status(503).json({
                success: false,
                message: 'الواتساب غير متصل'
            });
        }

        const isValid = await whatsappManager.isValidNumber(phone);

        res.json({
            success: true,
            data: {
                phone: phone,
                isValid: isValid,
                formatted: whatsappManager.formatPhoneNumber(phone)
            }
        });

    } catch (error) {
        logger.error('خطأ في التحقق من رقم الهاتف:', error);
        res.status(500).json({
            success: false,
            message: 'خطأ في التحقق من رقم الهاتف',
            error: error.message
        });
    }
});

// سجل الرسائل
app.get('/api/messages', (req, res) => {
    const { limit = 50, offset = 0 } = req.query;
    
    const messages = whatsappManager.messageHistory
        .slice(parseInt(offset), parseInt(offset) + parseInt(limit))
        .reverse();

    res.json({
        success: true,
        data: {
            messages: messages,
            total: whatsappManager.messageHistory.length,
            limit: parseInt(limit),
            offset: parseInt(offset)
        }
    });
});

// إحصائيات الخدمة
app.get('/api/stats', (req, res) => {
    const stats = {
        totalMessages: whatsappManager.messageHistory.length,
        sentMessages: whatsappManager.messageHistory.filter(m => m.direction === 'outgoing').length,
        receivedMessages: whatsappManager.messageHistory.filter(m => m.direction === 'incoming').length,
        queueLength: messageQueue.length,
        uptime: process.uptime(),
        connectionStatus: connectionStatus,
        isConnected: isConnected
    };

    res.json({
        success: true,
        data: stats
    });
});

// معالج الأخطاء العام
app.use((error, req, res, next) => {
    logger.error('خطأ في الخادم:', error);
    res.status(500).json({
        success: false,
        message: 'خطأ داخلي في الخادم',
        error: process.env.NODE_ENV === 'development' ? error.message : 'خطأ غير متوقع'
    });
});

// معالج الطرق غير الموجودة
app.use('*', (req, res) => {
    res.status(404).json({
        success: false,
        message: 'الطريق غير موجود'
    });
});

/**
 * المهام المجدولة
 */

// تنظيف السجلات القديمة (يومياً في منتصف الليل)
cron.schedule('0 0 * * *', () => {
    logger.info('بدء تنظيف السجلات القديمة...');
    
    // الاحتفاظ بآخر 1000 رسالة فقط
    if (whatsappManager.messageHistory.length > 1000) {
        whatsappManager.messageHistory = whatsappManager.messageHistory.slice(-1000);
        logger.info('تم تنظيف السجلات القديمة');
    }
});

// فحص حالة الاتصال (كل 5 دقائق)
cron.schedule('*/5 * * * *', () => {
    if (isConnected && whatsappManager.client) {
        // يمكن إضافة فحص إضافي لحالة الاتصال هنا
        logger.info('فحص حالة الاتصال - متصل');
    } else {
        logger.warn('فحص حالة الاتصال - غير متصل');
    }
});

/**
 * بدء الخادم
 */
const server = app.listen(PORT, HOST, () => {
    logger.info(`🚀 خدمة الواتساب تعمل على ${HOST}:${PORT}`);
    logger.info(`📱 جاهز لاستقبال الطلبات...`);
    
    // بدء الجلسة تلقائياً
    setTimeout(() => {
        logger.info('بدء جلسة الواتساب تلقائياً...');
        whatsappManager.startSession();
    }, 2000);
});

// معالجة إنهاء التطبيق بشكل صحيح
process.on('SIGTERM', async () => {
    logger.info('تلقي إشارة SIGTERM، إنهاء الخدمة...');
    
    await whatsappManager.closeSession();
    server.close(() => {
        logger.info('تم إنهاء الخدمة بنجاح');
        process.exit(0);
    });
});

process.on('SIGINT', async () => {
    logger.info('تلقي إشارة SIGINT، إنهاء الخدمة...');
    
    await whatsappManager.closeSession();
    server.close(() => {
        logger.info('تم إنهاء الخدمة بنجاح');
        process.exit(0);
    });
});

// معالجة الأخطاء غير المعالجة
process.on('uncaughtException', (error) => {
    logger.error('خطأ غير معالج:', error);
    process.exit(1);
});

process.on('unhandledRejection', (reason, promise) => {
    logger.error('رفض غير معالج:', reason);
    process.exit(1);
});

module.exports = app;

