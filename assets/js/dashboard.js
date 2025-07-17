// Attendance System Dashboard JavaScript

class AttendanceSystem {
    constructor() {
        this.apiBase = 'api/';
        this.currentSection = 'dashboard';
        this.currentSession = null;
        this.scannerActive = false;
        this.autoRefreshInterval = null;
        
        this.init();
    }

    init() {
        this.setupEventListeners();
        this.loadInitialData();
        this.startAutoRefresh();
        this.setupNotifications();
    }

    // Event Listeners Setup
    setupEventListeners() {
        // Navigation
        document.querySelectorAll('[data-section]').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                this.switchSection(link.dataset.section);
            });
        });

        // Scanner
        document.getElementById('startScannerBtn').addEventListener('click', () => {
            this.startScanner();
        });

        document.getElementById('manualSubmitBtn').addEventListener('click', () => {
            this.handleManualBarcode();
        });

        document.getElementById('manualBarcodeInput').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                this.handleManualBarcode();
            }
        });

        // Session Management
        document.getElementById('activeSessionSelect').addEventListener('change', (e) => {
            this.selectSession(e.target.value);
        });

        document.getElementById('endSessionBtn').addEventListener('click', () => {
            this.endSession();
        });

        // Forms
        document.getElementById('addStudentForm').addEventListener('submit', (e) => {
            this.handleAddStudent(e);
        });

        document.getElementById('addClassForm').addEventListener('submit', (e) => {
            this.handleAddClass(e);
        });

        document.getElementById('addSessionForm').addEventListener('submit', (e) => {
            this.handleAddSession(e);
        });

        document.getElementById('quickRegisterForm').addEventListener('submit', (e) => {
            this.handleQuickRegister(e);
        });

        // Search and Filters
        document.getElementById('studentsSearch').addEventListener('input', 
            this.debounce(() => this.loadStudents(), 500));
        
        document.getElementById('studentsClassFilter').addEventListener('change', () => {
            this.loadStudents();
        });

        document.getElementById('classesSearch').addEventListener('input', 
            this.debounce(() => this.loadClasses(), 500));
        
        document.getElementById('classesGradeFilter').addEventListener('change', () => {
            this.loadClasses();
        });

        document.getElementById('sessionsSearch').addEventListener('input', 
            this.debounce(() => this.loadSessions(), 500));
        
        document.getElementById('sessionsClassFilter').addEventListener('change', () => {
            this.loadSessions();
        });

        document.getElementById('sessionsStatusFilter').addEventListener('change', () => {
            this.loadSessions();
        });

        document.getElementById('sessionsDateFilter').addEventListener('change', () => {
            this.loadSessions();
        });

        // Reports
        document.getElementById('generateReportBtn').addEventListener('click', () => {
            this.generateReport();
        });

        document.querySelectorAll('.report-card').forEach(card => {
            card.addEventListener('click', () => {
                const reportType = card.dataset.report;
                document.getElementById('reportType').value = reportType;
                this.generateReport();
            });
        });

        // WhatsApp
        document.getElementById('connectWhatsAppBtn').addEventListener('click', () => {
            this.connectWhatsApp();
        });

        document.getElementById('sendBulkMessageBtn').addEventListener('click', () => {
            this.sendBulkMessage();
        });
    }

    // Section Management
    switchSection(sectionName) {
        // Hide all sections
        document.querySelectorAll('.content-section').forEach(section => {
            section.classList.remove('active');
        });

        // Show target section
        document.getElementById(`${sectionName}-section`).classList.add('active');

        // Update navigation
        document.querySelectorAll('.nav-link').forEach(link => {
            link.classList.remove('active');
        });
        document.querySelector(`[data-section="${sectionName}"]`).classList.add('active');

        this.currentSection = sectionName;

        // Load section data
        this.loadSectionData(sectionName);
    }

    loadSectionData(section) {
        switch (section) {
            case 'dashboard':
                this.loadDashboard();
                break;
            case 'scanner':
                this.loadActiveSessions();
                break;
            case 'students':
                this.loadStudents();
                this.loadClassesForSelect();
                break;
            case 'classes':
                this.loadClasses();
                break;
            case 'sessions':
                this.loadSessions();
                this.loadClassesForSelect();
                break;
            case 'reports':
                this.loadClassesForSelect();
                this.setDefaultReportDates();
                break;
            case 'whatsapp':
                this.loadWhatsAppStatus();
                this.loadWhatsAppLogs();
                this.loadClassesForSelect();
                break;
        }
    }

    // API Helper Methods
    async apiCall(endpoint, options = {}) {
        const defaultOptions = {
            headers: {
                'Content-Type': 'application/json',
            },
        };

        const config = { ...defaultOptions, ...options };
        
        try {
            const response = await fetch(this.apiBase + endpoint, config);
            
            // Check if response is JSON before parsing
            const contentType = response.headers.get("content-type");
            if (contentType && contentType.indexOf("application/json") !== -1) {
                const data = await response.json();
                if (!response.ok) {
                    throw new Error(data.message || 'API Error');
                }
                return data;
            } else {
                // If not JSON, read as text and throw error
                const text = await response.text();
                console.error('Non-JSON API Response:', text);
                throw new Error(`استجابة غير متوقعة من الخادم. الحالة: ${response.status}.`);
            }
        } catch (error) {
            console.error('API Error:', error);
            this.showError('خطأ في الاتصال: ' + error.message);
            throw error;
        }
    }

    // Dashboard Methods
    async loadDashboard() {
        try {
            this.showLoading();
            
            const overview = await this.apiCall('dashboard.php?action=overview');
            this.updateDashboardStats(overview.data);
            
            const charts = await this.apiCall('dashboard.php?action=charts&type=attendance_trend');
            this.updateAttendanceTrendChart(charts.data);
            
            const classDistribution = await this.apiCall('dashboard.php?action=charts&type=class_distribution');
            this.updateClassDistributionChart(classDistribution.data);
            
            const recentActivity = await this.apiCall('dashboard.php?action=recent');
            this.updateRecentActivity(recentActivity.data);
            
            this.hideLoading();
        } catch (error) {
            this.hideLoading();
        }
    }

    updateDashboardStats(data) {
        document.getElementById('totalStudents').textContent = data.basic_stats.total_students;
        document.getElementById('todayPresent').textContent = data.today_stats.present_students;
        document.getElementById('todayAbsent').textContent = data.today_stats.absent_students;
        document.getElementById('activeSessions').textContent = data.basic_stats.active_sessions;

        // Update top classes
        const topClassesContainer = document.getElementById('topClasses');
        if (data.top_classes && data.top_classes.length > 0) {
            topClassesContainer.innerHTML = data.top_classes.map((cls, index) => `
                <div class="top-class-item">
                    <div class="top-class-rank">${index + 1}</div>
                    <div class="top-class-info">
                        <div class="top-class-name">${cls.class_name}</div>
                        <div class="top-class-grade">${cls.grade_level}</div>
                    </div>
                    <div class="top-class-rate">${cls.attendance_rate}%</div>
                </div>
            `).join('');
        } else {
            topClassesContainer.innerHTML = '<div class="text-center text-muted">لا توجد بيانات</div>';
        }
    }

    updateAttendanceTrendChart(data) {
        const ctx = document.getElementById('attendanceTrendChart').getContext('2d');
        
        if (window.attendanceTrendChart) {
            window.attendanceTrendChart.destroy();
        }
        
        window.attendanceTrendChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: data.datasets.map(dataset => ({
                    ...dataset,
                    tension: 0.4,
                    fill: true
                }))
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }

    updateClassDistributionChart(data) {
        const ctx = document.getElementById('classDistributionChart').getContext('2d');
        
        if (window.classDistributionChart) {
            window.classDistributionChart.destroy();
        }
        
        window.classDistributionChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: data.datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                    }
                }
            }
        });
    }

    updateRecentActivity(data) {
        const container = document.getElementById('recentActivity');
        
        if (data && data.length > 0) {
            container.innerHTML = data.map(activity => `
                <div class="activity-item">
                    <div class="activity-icon ${activity.type}">
                        <i class="fas ${activity.type === 'attendance' ? 'fa-user-check' : 'fa-calendar-plus'}"></i>
                    </div>
                    <div class="activity-content">
                        <div class="activity-title">${activity.action}</div>
                        <div class="activity-description">
                            ${activity.student_name || activity.subject} - ${activity.class_name}
                        </div>
                    </div>
                    <div class="activity-time">${this.formatTime(activity.timestamp)}</div>
                </div>
            `).join('');
        } else {
            container.innerHTML = `
                <div class="text-center text-muted">
                    <i class="fas fa-users"></i>
                    <p class="mb-0">لا توجد أنشطة حديثة</p>
                </div>
            `;
        }
    }

    // Scanner Methods
    async loadActiveSessions() {
        try {
            const response = await this.apiCall('sessions.php?action=active');
            const sessions = response.data;
            
            const select = document.getElementById('activeSessionSelect');
            select.innerHTML = '<option value="">اختر الجلسة النشطة</option>';
            
            sessions.forEach(session => {
                const option = document.createElement('option');
                option.value = session.id;
                option.textContent = `${session.subject} - ${session.class_name} (${session.date})`;
                select.appendChild(option);
            });
            
            // Enable/disable scanner based on available sessions
            const startBtn = document.getElementById('startScannerBtn');
            startBtn.disabled = sessions.length === 0;
            
            if (sessions.length === 0) {
                document.getElementById('scanner-placeholder').innerHTML = `
                    <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                    <p class="text-muted">لا توجد جلسات نشطة حالياً</p>
                    <p class="text-muted">يجب إنشاء جلسة جديدة أولاً</p>
                `;
            }
        } catch (error) {
            console.error('Error loading active sessions:', error);
        }
    }

    selectSession(sessionId) {
        if (sessionId) {
            this.currentSession = sessionId;
            document.getElementById('startScannerBtn').disabled = false;
            document.querySelector('.manual-input').style.display = 'block';
            this.loadSessionStats(sessionId);
        } else {
            this.currentSession = null;
            document.getElementById('startScannerBtn').disabled = true;
            document.querySelector('.manual-input').style.display = 'none';
            this.stopScanner();
        }
    }

    async loadSessionStats(sessionId) {
        try {
            const response = await this.apiCall(`attendance.php?action=session&session_id=${sessionId}`);
            const data = response.data;
            
            document.getElementById('sessionTotalStudents').textContent = data.stats.total_students;
            document.getElementById('sessionPresentCount').textContent = data.stats.present_count;
            document.getElementById('sessionAbsentCount').textContent = data.stats.absent_count;
            document.getElementById('sessionAttendanceRate').textContent = data.stats.attendance_rate + '%';
            
            // Enable end session button
            document.getElementById('endSessionBtn').disabled = false;
            
            // Update live attendance list
            this.updateLiveAttendanceList(data.attendance);
        } catch (error) {
            console.error('Error loading session stats:', error);
        }
    }

    updateLiveAttendanceList(attendance) {
        const container = document.getElementById('liveAttendanceList');
        const presentStudents = attendance.filter(a => a.status === 'present');
        
        if (presentStudents.length > 0) {
            container.innerHTML = presentStudents.map(student => `
                <div class="attendance-item">
                    <div class="attendance-avatar">
                        ${student.student_name.charAt(0)}
                    </div>
                    <div class="attendance-info">
                        <div class="attendance-name">${student.student_name}</div>
                        <div class="attendance-time">${this.formatTime(student.attendance_time)}</div>
                    </div>
                    <div class="attendance-status status-present">حاضر</div>
                </div>
            `).join('');
        } else {
            container.innerHTML = `
                <div class="text-center text-muted">
                    <i class="fas fa-users"></i>
                    <p class="mb-0">لا توجد بيانات حضور</p>
                </div>
            `;
        }
    }

    startScanner() {
        if (!this.currentSession) {
            this.showError('يجب اختيار جلسة نشطة أولاً');
            return;
        }

        const scannerContainer = document.getElementById('scanner');
        const placeholder = document.getElementById('scanner-placeholder');
        
        placeholder.style.display = 'none';
        scannerContainer.style.display = 'block';
        
        Quagga.init({
            inputStream: {
                name: "Live",
                type: "LiveStream",
                target: scannerContainer,
                constraints: {
                    width: 500,
                    height: 300,
                    facingMode: "environment"
                }
            },
            decoder: {
                readers: [
                    "code_128_reader",
                    "ean_reader",
                    "ean_8_reader",
                    "code_39_reader",
                    "code_39_vin_reader",
                    "codabar_reader",
                    "upc_reader",
                    "upc_e_reader"
                ]
            }
        }, (err) => {
            if (err) {
                console.error('Scanner initialization error:', err);
                this.showError('خطأ في تشغيل الماسح: ' + err.message);
                this.stopScanner();
                return;
            }
            
            Quagga.start();
            this.scannerActive = true;
            
            // Update button
            const startBtn = document.getElementById('startScannerBtn');
            startBtn.innerHTML = '<i class="fas fa-stop me-2"></i>إيقاف المسح';
            startBtn.onclick = () => this.stopScanner();
        });

        Quagga.onDetected((data) => {
            if (this.scannerActive) {
                const barcode = data.codeResult.code;
                this.handleBarcodeDetected(barcode);
            }
        });
    }

    stopScanner() {
        if (this.scannerActive) {
            Quagga.stop();
            this.scannerActive = false;
        }
        
        const scannerContainer = document.getElementById('scanner');
        const placeholder = document.getElementById('scanner-placeholder');
        
        scannerContainer.style.display = 'none';
        placeholder.style.display = 'block';
        
        // Reset button
        const startBtn = document.getElementById('startScannerBtn');
        startBtn.innerHTML = '<i class="fas fa-play me-2"></i>بدء المسح';
        startBtn.onclick = () => this.startScanner();
    }

    async handleBarcodeDetected(barcode) {
        try {
            const response = await this.apiCall(`attendance.php?action=scan&barcode=${barcode}&session_id=${this.currentSession}`);
            
            if (response.data.status === 'success') {
                // Success - student found and attendance marked
                this.showSuccess(`تم تسجيل حضور ${response.data.student.name} بنجاح`);
                this.playSuccessSound();
                
                // Auto advance - clear manual input and focus
                document.getElementById('manualBarcodeInput').value = '';
                document.getElementById('manualBarcodeInput').focus();
                
                // Refresh session stats
                this.loadSessionStats(this.currentSession);
                
            } else if (response.data.status === 'student_not_found') {
                // Student not found - show quick registration modal
                this.showQuickRegistrationModal(barcode);
                this.playErrorSound();
            }
            
        } catch (error) {
            this.showError('خطأ في تسجيل الحضور: ' + error.message);
            this.playErrorSound();
        }
    }

    handleManualBarcode() {
        const barcode = document.getElementById('manualBarcodeInput').value.trim();
        if (barcode) {
            this.handleBarcodeDetected(barcode);
        }
    }

    showQuickRegistrationModal(barcode) {
        document.getElementById('quickRegisterBarcode').value = barcode;
        document.getElementById('quickRegisterSessionId').value = this.currentSession;
        
        const modal = new bootstrap.Modal(document.getElementById('quickRegisterModal'));
        modal.show();
    }

    async handleQuickRegister(e) {
        e.preventDefault();
        
        const formData = new FormData(e.target);
        const data = {
            action: 'quick_register',
            barcode: formData.get('barcode'),
            name: formData.get('name'),
            parent_phone: formData.get('parent_phone'),
            grade_level: formData.get('grade_level'),
            session_id: formData.get('session_id')
        };
        
        try {
            this.showLoading();
            
            const response = await this.apiCall('attendance.php', {
                method: 'POST',
                body: JSON.stringify(data)
            });
            
            this.showSuccess('تم تسجيل الطالب وتسجيل الحضور بنجاح');
            this.playSuccessSound();
            
            // Close modal and refresh
            bootstrap.Modal.getInstance(document.getElementById('quickRegisterModal')).hide();
            e.target.reset();
            
            // Auto advance
            document.getElementById('manualBarcodeInput').value = '';
            document.getElementById('manualBarcodeInput').focus();
            
            // Refresh session stats
            this.loadSessionStats(this.currentSession);
            
            this.hideLoading();
        } catch (error) {
            this.hideLoading();
        }
    }

    async endSession() {
        if (!this.currentSession) return;
        
        const result = await Swal.fire({
            title: 'إنهاء الجلسة',
            text: 'هل أنت متأكد من إنهاء الجلسة؟ سيتم إرسال التقارير لأولياء الأمور.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'نعم، إنهاء الجلسة',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#dc3545'
        });
        
        if (result.isConfirmed) {
            try {
                this.showLoading();
                
                const response = await this.apiCall('sessions.php', {
                    method: 'PUT',
                    body: JSON.stringify({
                        id: this.currentSession,
                        action: 'end'
                    })
                });
                
                this.showSuccess('تم إنهاء الجلسة بنجاح');
                
                // Reset scanner
                this.stopScanner();
                this.currentSession = null;
                document.getElementById('activeSessionSelect').value = '';
                document.getElementById('endSessionBtn').disabled = true;
                
                // Reload active sessions
                this.loadActiveSessions();
                
                this.hideLoading();
            } catch (error) {
                this.hideLoading();
            }
        }
    }

    // Students Management
    async loadStudents(page = 1) {
        try {
            const search = document.getElementById('studentsSearch').value;
            const classFilter = document.getElementById('studentsClassFilter').value;
            
            let url = `students.php?action=list&page=${page}&limit=10`;
            if (search) url += `&search=${encodeURIComponent(search)}`;
            if (classFilter) url += `&class_id=${classFilter}`;
            
            const response = await this.apiCall(url);
            this.updateStudentsTable(response.data.students);
            this.updatePagination('studentsPagination', response.data.pagination, (p) => this.loadStudents(p));
        } catch (error) {
            console.error('Error loading students:', error);
        }
    }

    updateStudentsTable(students) {
        const tbody = document.querySelector('#studentsTable tbody');
        
        if (students.length > 0) {
            tbody.innerHTML = students.map(student => `
                <tr>
                    <td>
                        <div class="d-flex align-items-center">
                            <div class="attendance-avatar me-2">
                                ${student.name.charAt(0)}
                            </div>
                            <div>
                                <div class="fw-bold">${student.name}</div>
                                <small class="text-muted">${student.grade_level || ''}</small>
                            </div>
                        </div>
                    </td>
                    <td><code>${student.barcode}</code></td>
                    <td>${student.class_name || 'غير محدد'}</td>
                    <td>${student.parent_phone || 'غير محدد'}</td>
                    <td>
                        <span class="badge ${student.attendance_rate >= 80 ? 'bg-success' : student.attendance_rate >= 60 ? 'bg-warning' : 'bg-danger'}">
                            ${student.attendance_rate}%
                        </span>
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-primary" onclick="attendanceSystem.viewStudentDetails(${student.id})">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn btn-outline-success" onclick="attendanceSystem.editStudent(${student.id})">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-outline-danger" onclick="attendanceSystem.deleteStudent(${student.id})">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `).join('');
        } else {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="text-center text-muted">
                        <i class="fas fa-users fa-2x mb-2"></i>
                        <p>لا توجد طلاب</p>
                    </td>
                </tr>
            `;
        }
    }

    async handleAddStudent(e) {
        e.preventDefault();
        
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData.entries());
        
        try {
            this.showLoading();
            
            const response = await this.apiCall('students.php', {
                method: 'POST',
                body: JSON.stringify(data)
            });
            
            this.showSuccess('تم إضافة الطالب بنجاح');
            
            // Close modal and refresh
            bootstrap.Modal.getInstance(document.getElementById('addStudentModal')).hide();
            e.target.reset();
            this.loadStudents();
            
            this.hideLoading();
        } catch (error) {
            this.hideLoading();
        }
    }

    // Classes Management
    async loadClasses() {
        try {
            const search = document.getElementById('classesSearch').value;
            const gradeFilter = document.getElementById('classesGradeFilter').value;
            
            let url = 'classes.php?action=list&limit=50';
            if (search) url += `&search=${encodeURIComponent(search)}`;
            if (gradeFilter) url += `&grade_level=${encodeURIComponent(gradeFilter)}`;
            
            const response = await this.apiCall(url);
            this.updateClassesGrid(response.data.classes);
        } catch (error) {
            console.error('Error loading classes:', error);
        }
    }

    updateClassesGrid(classes) {
        const container = document.getElementById('classesGrid');
        
        if (classes.length > 0) {
            container.innerHTML = classes.map(cls => `
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="class-card">
                        <div class="class-card-header">
                            <h5 class="mb-1">${cls.name}</h5>
                            <small>${cls.grade_level}</small>
                        </div>
                        <div class="class-card-body">
                            <p class="text-muted mb-3">${cls.description || 'لا يوجد وصف'}</p>
                            
                            <div class="class-stats">
                                <div class="class-stat">
                                    <div class="class-stat-value">${cls.total_students}</div>
                                    <div class="class-stat-label">طالب</div>
                                </div>
                                <div class="class-stat">
                                    <div class="class-stat-value">${cls.total_sessions}</div>
                                    <div class="class-stat-label">جلسة</div>
                                </div>
                                <div class="class-stat">
                                    <div class="class-stat-value">${cls.avg_attendance_rate}%</div>
                                    <div class="class-stat-label">حضور</div>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <div class="btn-group w-100">
                                    <button class="btn btn-outline-primary btn-sm" onclick="attendanceSystem.viewClassDetails(${cls.id})">
                                        <i class="fas fa-eye"></i> عرض
                                    </button>
                                    <button class="btn btn-outline-success btn-sm" onclick="attendanceSystem.editClass(${cls.id})">
                                        <i class="fas fa-edit"></i> تعديل
                                    </button>
                                    <button class="btn btn-outline-danger btn-sm" onclick="attendanceSystem.deleteClass(${cls.id})">
                                        <i class="fas fa-trash"></i> حذف
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `).join('');
        } else {
            container.innerHTML = `
                <div class="col-12 text-center">
                    <i class="fas fa-chalkboard fa-3x text-muted mb-3"></i>
                    <p class="text-muted">لا توجد فصول</p>
                </div>
            `;
        }
    }

    async handleAddClass(e) {
        e.preventDefault();
        
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData.entries());
        
        try {
            this.showLoading();
            
            const response = await this.apiCall('classes.php', {
                method: 'POST',
                body: JSON.stringify(data)
            });
            
            this.showSuccess('تم إضافة الفصل بنجاح');
            
            // Close modal and refresh
            bootstrap.Modal.getInstance(document.getElementById('addClassModal')).hide();
            e.target.reset();
            this.loadClasses();
            this.loadClassesForSelect();
            
            this.hideLoading();
        } catch (error) {
            this.hideLoading();
        }
    }

    // Sessions Management
    async loadSessions(page = 1) {
        try {
            const search = document.getElementById('sessionsSearch').value;
            const classFilter = document.getElementById('sessionsClassFilter').value;
            const statusFilter = document.getElementById('sessionsStatusFilter').value;
            const dateFilter = document.getElementById('sessionsDateFilter').value;
            
            let url = `sessions.php?action=list&page=${page}&limit=10`;
            if (search) url += `&search=${encodeURIComponent(search)}`;
            if (classFilter) url += `&class_id=${classFilter}`;
            if (statusFilter) url += `&status=${statusFilter}`;
            if (dateFilter) url += `&date_from=${dateFilter}&date_to=${dateFilter}`;
            
            const response = await this.apiCall(url);
            this.updateSessionsTable(response.data.sessions);
            this.updatePagination('sessionsPagination', response.data.pagination, (p) => this.loadSessions(p));
        } catch (error) {
            console.error('Error loading sessions:', error);
        }
    }

    updateSessionsTable(sessions) {
        const tbody = document.querySelector('#sessionsTable tbody');
        
        if (sessions.length > 0) {
            tbody.innerHTML = sessions.map(session => `
                <tr>
                    <td>
                        <div class="fw-bold">${session.subject}</div>
                        <small class="text-muted">${session.description || ''}</small>
                    </td>
                    <td>${session.class_name}</td>
                    <td>${this.formatDate(session.date)}</td>
                    <td>
                        ${session.start_time}
                        ${session.end_time ? ` - ${session.end_time}` : ''}
                    </td>
                    <td>
                        <span class="badge ${this.getStatusBadgeClass(session.status)}">
                            ${this.getStatusText(session.status)}
                        </span>
                    </td>
                    <td>
                        <span class="badge bg-info">
                            ${session.present_count}/${session.total_students}
                        </span>
                        <small class="text-muted d-block">${session.attendance_rate}%</small>
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-primary" onclick="attendanceSystem.viewSessionDetails(${session.id})">
                                <i class="fas fa-eye"></i>
                            </button>
                            ${session.status === 'active' ? `
                                <button class="btn btn-outline-danger" onclick="attendanceSystem.endSessionFromTable(${session.id})">
                                    <i class="fas fa-stop"></i>
                                </button>
                            ` : ''}
                            <button class="btn btn-outline-success" onclick="attendanceSystem.editSession(${session.id})">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-outline-danger" onclick="attendanceSystem.deleteSession(${session.id})">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `).join('');
        } else {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center text-muted">
                        <i class="fas fa-calendar-alt fa-2x mb-2"></i>
                        <p>لا توجد جلسات</p>
                    </td>
                </tr>
            `;
        }
    }

    async handleAddSession(e) {
        e.preventDefault();
        
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData.entries());
        
        try {
            this.showLoading();
            
            const response = await this.apiCall('sessions.php', {
                method: 'POST',
                body: JSON.stringify(data)
            });
            
            this.showSuccess('تم إنشاء الجلسة بنجاح');
            
            // Close modal and refresh
            bootstrap.Modal.getInstance(document.getElementById('addSessionModal')).hide();
            e.target.reset();
            this.loadSessions();
            this.loadActiveSessions();
            
            this.hideLoading();
        } catch (error) {
            this.hideLoading();
        }
    }

    // Reports
    async generateReport() {
        const type = document.getElementById('reportType').value;
        const dateFrom = document.getElementById('reportDateFrom').value;
        const dateTo = document.getElementById('reportDateTo').value;
        const classFilter = document.getElementById('reportClassFilter').value;
        const format = document.getElementById('reportFormat').value;
        
        try {
            this.showLoading();
            
            let url = `reports.php?type=${type}&format=${format}`;
            if (dateFrom) url += `&date_from=${dateFrom}`;
            if (dateTo) url += `&date_to=${dateTo}`;
            if (classFilter) url += `&class_id=${classFilter}`;
            
            if (format === 'csv') {
                // Download CSV
                window.open(url, '_blank');
            } else {
                // Display on screen
                const response = await this.apiCall(url);
                this.displayReport(response.data);
            }
            
            this.hideLoading();
        } catch (error) {
            this.hideLoading();
        }
    }

    displayReport(data) {
        const container = document.getElementById('reportContent');
        const resultsCard = document.getElementById('reportResults');
        
        // Show results card
        resultsCard.style.display = 'block';
        
        // Generate report HTML based on type
        let html = `
            <div class="row mb-4">
                <div class="col-12">
                    <h5>تقرير ${data.report_info.type}</h5>
                    <p class="text-muted">
                        الفترة: ${data.report_info.date_from || 'غير محدد'} إلى ${data.report_info.date_to || 'غير محدد'}
                        | تاريخ الإنشاء: ${this.formatDateTime(data.report_info.generated_at)}
                    </p>
                </div>
            </div>
        `;
        
        // Add statistics if available
        if (data.statistics) {
            html += '<div class="row mb-4">';
            Object.entries(data.statistics).forEach(([key, value]) => {
                html += `
                    <div class="col-md-3 mb-2">
                        <div class="card text-center">
                            <div class="card-body py-2">
                                <h6 class="card-title mb-1">${this.translateStatKey(key)}</h6>
                                <h4 class="text-primary mb-0">${value}</h4>
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
        }
        
        // Add data table if available
        if (data.data && data.data.length > 0) {
            html += `
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                ${Object.keys(data.data[0]).map(key => `<th>${this.translateTableHeader(key)}</th>`).join('')}
                            </tr>
                        </thead>
                        <tbody>
                            ${data.data.map(row => `
                                <tr>
                                    ${Object.values(row).map(value => `<td>${value || '-'}</td>`).join('')}
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        }
        
        container.innerHTML = html;
    }

    // WhatsApp Management
    async loadWhatsAppStatus() {
        try {
            // This would connect to the WhatsApp service
            // For now, we'll simulate the status
            const statusElement = document.getElementById('whatsappStatus');
            statusElement.innerHTML = `
                <div class="status-dot bg-secondary"></div>
                <span>غير متصل</span>
            `;
        } catch (error) {
            console.error('Error loading WhatsApp status:', error);
        }
    }

    async loadWhatsAppLogs() {
        try {
            // This would load WhatsApp message logs
            // For now, we'll show a placeholder
            const tbody = document.querySelector('#whatsappLogsTable tbody');
            tbody.innerHTML = `
                <tr>
                    <td colspan="4" class="text-center text-muted">
                        <i class="fab fa-whatsapp fa-2x mb-2"></i>
                        <p>لا توجد رسائل</p>
                    </td>
                </tr>
            `;
        } catch (error) {
            console.error('Error loading WhatsApp logs:', error);
        }
    }

    // Helper Methods
    async loadClassesForSelect() {
        try {
            const response = await this.apiCall('classes.php?action=list&limit=100');
            const classes = response.data.classes;
            
            // Update all class select elements
            const selects = [
                'studentsClassFilter',
                'sessionsClassFilter', 
                'reportClassFilter',
                'whatsappTargetClass'
            ];
            
            selects.forEach(selectId => {
                const select = document.getElementById(selectId);
                if (select) {
                    const currentValue = select.value;
                    const firstOption = select.querySelector('option').outerHTML;
                    
                    select.innerHTML = firstOption + classes.map(cls => 
                        `<option value="${cls.id}">${cls.name}</option>`
                    ).join('');
                    
                    select.value = currentValue;
                }
            });
            
            // Update form selects in modals
            const formSelects = document.querySelectorAll('select[name="class_id"]');
            formSelects.forEach(select => {
                const currentValue = select.value;
                const firstOption = select.querySelector('option').outerHTML;
                
                select.innerHTML = firstOption + classes.map(cls => 
                    `<option value="${cls.id}">${cls.name}</option>`
                ).join('');
                
                select.value = currentValue;
            });
            
        } catch (error) {
            console.error('Error loading classes for select:', error);
        }
    }

    setDefaultReportDates() {
        const today = new Date();
        const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
        
        document.getElementById('reportDateFrom').value = this.formatDateForInput(firstDay);
        document.getElementById('reportDateTo').value = this.formatDateForInput(today);
    }

    updatePagination(containerId, pagination, callback) {
        const container = document.getElementById(containerId);
        
        if (pagination.total_pages <= 1) {
            container.innerHTML = '';
            return;
        }
        
        let html = '';
        
        // Previous button
        if (pagination.current_page > 1) {
            html += `<li class="page-item">
                <a class="page-link" href="#" data-page="${pagination.current_page - 1}">السابق</a>
            </li>`;
        }
        
        // Page numbers
        const startPage = Math.max(1, pagination.current_page - 2);
        const endPage = Math.min(pagination.total_pages, pagination.current_page + 2);
        
        for (let i = startPage; i <= endPage; i++) {
            html += `<li class="page-item ${i === pagination.current_page ? 'active' : ''}">
                <a class="page-link" href="#" data-page="${i}">${i}</a>
            </li>`;
        }
        
        // Next button
        if (pagination.current_page < pagination.total_pages) {
            html += `<li class="page-item">
                <a class="page-link" href="#" data-page="${pagination.current_page + 1}">التالي</a>
            </li>`;
        }
        
        container.innerHTML = html;
        
        // Add click handlers
        container.querySelectorAll('.page-link').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const page = parseInt(e.target.dataset.page);
                callback(page);
            });
        });
    }

    // Auto Refresh
    startAutoRefresh() {
        this.autoRefreshInterval = setInterval(() => {
            if (this.currentSection === 'dashboard') {
                this.loadDashboard();
            } else if (this.currentSection === 'scanner' && this.currentSession) {
                this.loadSessionStats(this.currentSession);
            }
        }, 30000); // Refresh every 30 seconds
    }

    // Notifications
    setupNotifications() {
        // Load alerts periodically
        setInterval(() => {
            this.loadAlerts();
        }, 60000); // Check every minute
        
        this.loadAlerts();
    }

    async loadAlerts() {
        try {
            const response = await this.apiCall('dashboard.php?action=alerts');
            this.updateAlertsDropdown(response.data.alerts);
            document.getElementById('alertsCount').textContent = response.data.total_alerts;
        } catch (error) {
            console.error('Error loading alerts:', error);
        }
    }

    updateAlertsDropdown(alerts) {
        const dropdown = document.getElementById('alertsDropdown');
        
        if (alerts.length > 0) {
            const alertsHtml = alerts.map(alert => `
                <li>
                    <a class="dropdown-item" href="#">
                        <div class="d-flex">
                            <i class="fas ${this.getAlertIcon(alert.type)} text-${alert.type === 'danger' ? 'danger' : alert.type === 'warning' ? 'warning' : 'info'} me-2 mt-1"></i>
                            <div>
                                <div class="fw-bold">${alert.title}</div>
                                <small class="text-muted">${alert.message}</small>
                            </div>
                        </div>
                    </a>
                </li>
            `).join('');
            
            dropdown.innerHTML = `
                <li><h6 class="dropdown-header">الإشعارات الحديثة</h6></li>
                <li><hr class="dropdown-divider"></li>
                ${alertsHtml}
            `;
        } else {
            dropdown.innerHTML = `
                <li><h6 class="dropdown-header">الإشعارات الحديثة</h6></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-muted" href="#">لا توجد إشعارات جديدة</a></li>
            `;
        }
    }

    // Utility Methods
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('ar-SA');
    }

    formatTime(timeString) {
        const date = new Date(timeString);
        return date.toLocaleTimeString('ar-SA', { 
            hour: '2-digit', 
            minute: '2-digit' 
        });
    }

    formatDateTime(dateTimeString) {
        const date = new Date(dateTimeString);
        return date.toLocaleString('ar-SA');
    }

    formatDateForInput(date) {
        return date.toISOString().split('T')[0];
    }

    getStatusBadgeClass(status) {
        switch (status) {
            case 'active': return 'bg-success';
            case 'completed': return 'bg-primary';
            case 'cancelled': return 'bg-danger';
            default: return 'bg-secondary';
        }
    }

    getStatusText(status) {
        switch (status) {
            case 'active': return 'نشطة';
            case 'completed': return 'مكتملة';
            case 'cancelled': return 'ملغية';
            default: return 'غير محدد';
        }
    }

    getAlertIcon(type) {
        switch (type) {
            case 'danger': return 'fa-exclamation-triangle';
            case 'warning': return 'fa-exclamation-circle';
            case 'info': return 'fa-info-circle';
            default: return 'fa-bell';
        }
    }

    translateStatKey(key) {
        const translations = {
            'total_students': 'إجمالي الطلاب',
            'total_attendance': 'إجمالي الحضور',
            'attendance_rate': 'معدل الحضور',
            'avg_quiz_score': 'متوسط درجة الكويز',
            'present_count': 'الحاضرون',
            'absent_count': 'الغائبون',
            'on_time_count': 'في الوقت',
            'late_count': 'متأخرون'
        };
        return translations[key] || key;
    }

    translateTableHeader(key) {
        const translations = {
            'student_name': 'اسم الطالب',
            'class_name': 'الفصل',
            'attendance_time': 'وقت الحضور',
            'teacher_rating': 'تقييم المدرس',
            'quiz_score': 'درجة الكويز',
            'subject': 'المادة',
            'date': 'التاريخ',
            'attendance_rate': 'معدل الحضور'
        };
        return translations[key] || key;
    }

    // UI Feedback Methods
    showLoading() {
        document.getElementById('loadingOverlay').style.display = 'flex';
    }

    hideLoading() {
        document.getElementById('loadingOverlay').style.display = 'none';
    }

    showSuccess(message) {
        Swal.fire({
            icon: 'success',
            title: 'نجح!',
            text: message,
            timer: 3000,
            showConfirmButton: false
        });
    }

    showError(message) {
        Swal.fire({
            icon: 'error',
            title: 'خطأ!',
            text: message
        });
    }

    playSuccessSound() {
        // Create a simple success sound
        const audioContext = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = audioContext.createOscillator();
        const gainNode = audioContext.createGain();
        
        oscillator.connect(gainNode);
        gainNode.connect(audioContext.destination);
        
        oscillator.frequency.setValueAtTime(800, audioContext.currentTime);
        oscillator.frequency.setValueAtTime(1000, audioContext.currentTime + 0.1);
        
        gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
        gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.3);
        
        oscillator.start(audioContext.currentTime);
        oscillator.stop(audioContext.currentTime + 0.3);
    }

    playErrorSound() {
        // Create a simple error sound
        const audioContext = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = audioContext.createOscillator();
        const gainNode = audioContext.createGain();
        
        oscillator.connect(gainNode);
        gainNode.connect(audioContext.destination);
        
        oscillator.frequency.setValueAtTime(400, audioContext.currentTime);
        oscillator.frequency.setValueAtTime(300, audioContext.currentTime + 0.1);
        
        gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
        gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.3);
        
        oscillator.start(audioContext.currentTime);
        oscillator.stop(audioContext.currentTime + 0.3);
    }

    // Initial Data Load
    async loadInitialData() {
        try {
            // Set default dates for session form
            const today = new Date();
            document.querySelector('input[name="date"]').value = this.formatDateForInput(today);
            
            // Load dashboard by default
            this.loadDashboard();
        } catch (error) {
            console.error('Error loading initial data:', error);
        }
    }

    // Placeholder methods for future implementation
    async viewStudentDetails(studentId) {
        this.showError('هذه الميزة قيد التطوير');
    }

    async editStudent(studentId) {
        this.showError('هذه الميزة قيد التطوير');
    }

    async deleteStudent(studentId) {
        this.showError('هذه الميزة قيد التطوير');
    }

    async viewClassDetails(classId) {
        this.showError('هذه الميزة قيد التطوير');
    }

    async editClass(classId) {
        this.showError('هذه الميزة قيد التطوير');
    }

    async deleteClass(classId) {
        this.showError('هذه الميزة قيد التطوير');
    }

    async viewSessionDetails(sessionId) {
        this.showError('هذه الميزة قيد التطوير');
    }

    async editSession(sessionId) {
        this.showError('هذه الميزة قيد التطوير');
    }

    async deleteSession(sessionId) {
        this.showError('هذه الميزة قيد التطوير');
    }

    async endSessionFromTable(sessionId) {
        this.currentSession = sessionId;
        this.endSession();
    }

    async connectWhatsApp() {
        this.showError('خدمة الواتساب قيد التطوير');
    }

    async sendBulkMessage() {
        this.showError('خدمة الواتساب قيد التطوير');
    }
}

// Initialize the system when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.attendanceSystem = new AttendanceSystem();
});



