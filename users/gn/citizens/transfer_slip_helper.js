// transfer_slip_helper.js - Helper functions for displaying transfer slip

function generateTransferSlipHTML(slip) {
    // Generate members table rows
    let membersRows = '';
    if (slip.members && slip.members.length > 0) {
        slip.members.forEach((member, index) => {
            membersRows += `
                <tr>
                    <td>${index + 1}</td>
                    <td>${escapeHtml(member.full_name)}</td>
                    <td>${escapeHtml(member.identification_number)}</td>
                    <td>${escapeHtml(member.relation_to_head || 'Self')}</td>
                    <td>${member.gender.charAt(0).toUpperCase() + member.gender.slice(1)}</td>
                    <td>${formatDate(member.date_of_birth)}</td>
                    <td>${escapeHtml(member.mobile_phone || 'N/A')}</td>
                </tr>
            `;
        });
    }
    
    // Generate land details rows
    let landRows = '';
    if (slip.land_details && slip.land_details.length > 0) {
        slip.land_details.forEach((land, index) => {
            landRows += `
                <tr>
                    <td>${index + 1}</td>
                    <td>${land.land_type.charAt(0).toUpperCase() + land.land_type.slice(1)}</td>
                    <td>${land.land_size_perches}</td>
                    <td>${escapeHtml(land.deed_number || 'N/A')}</td>
                    <td>${escapeHtml(land.land_address || 'N/A')}</td>
                </tr>
            `;
        });
    }
    
    const landSection = slip.land_details && slip.land_details.length > 0 ? `
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h6 class="mb-0"><i class="bi bi-geo"></i> LAND DETAILS</h6>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-bordered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Land Type</th>
                                    <th>Size (Perches)</th>
                                    <th>Deed Number</th>
                                    <th>Address</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${landRows}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    ` : '';
    
    return `
        <div class="col-md-12">
            <div class="card transfer-slip">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-receipt"></i> Transfer Slip</h5>
                    <div class="btn-group">
                        <button class="btn btn-light btn-sm" onclick="window.print()">
                            <i class="bi bi-printer"></i> Print
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Transfer Slip Header -->
                    <div class="text-center mb-4">
                        <h3 class="text-primary">FAMILY TRANSFER SLIP</h3>
                        <h5>Ministry of Home Affairs - Sri Lanka</h5>
                        <p class="text-muted">Family Profile Management System</p>
                        <hr>
                    </div>
                    
                    <!-- Transfer Details -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <table class="table table-bordered">
                                <tr>
                                    <th width="40%">Transfer ID:</th>
                                    <td class="font-monospace">${escapeHtml(slip.transfer_id)}</td>
                                </tr>
                                <tr>
                                    <th>Family ID:</th>
                                    <td class="font-monospace">${escapeHtml(slip.family_id)}</td>
                                </tr>
                                <tr>
                                    <th>Transfer Date:</th>
                                    <td>${escapeHtml(slip.request_date)}</td>
                                </tr>
                                <tr>
                                    <th>Requested By:</th>
                                    <td>${escapeHtml(slip.requested_by)}</td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-bordered">
                                <tr>
                                    <th width="40%">Status:</th>
                                    <td><span class="badge bg-warning">PENDING APPROVAL</span></td>
                                </tr>
                                <tr>
                                    <th>Reason:</th>
                                    <td>${escapeHtml(slip.transfer_reason)}</td>
                                </tr>
                                <tr>
                                    <th>Notes:</th>
                                    <td>${escapeHtml(slip.transfer_notes)}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <!-- From and To Details -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-danger text-white">
                                    <h6 class="mb-0"><i class="bi bi-box-arrow-left"></i> TRANSFERRING FROM</h6>
                                </div>
                                <div class="card-body">
                                    <p class="mb-1"><strong>GN Division:</strong> ${escapeHtml(slip.from_gn.GN)}</p>
                                    <p class="mb-1"><strong>Division:</strong> ${escapeHtml(slip.from_gn.Division_Name)}</p>
                                    <p class="mb-1"><strong>District:</strong> ${escapeHtml(slip.from_gn.District_Name)}</p>
                                    <p class="mb-0"><strong>Province:</strong> ${escapeHtml(slip.from_gn.Province_Name)}</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-success text-white">
                                    <h6 class="mb-0"><i class="bi bi-box-arrow-right"></i> TRANSFERRING TO</h6>
                                </div>
                                <div class="card-body">
                                    <p class="mb-1"><strong>GN Division:</strong> ${escapeHtml(slip.to_gn.GN)}</p>
                                    <p class="mb-1"><strong>Division:</strong> ${escapeHtml(slip.to_gn.Division_Name)}</p>
                                    <p class="mb-1"><strong>District:</strong> ${escapeHtml(slip.to_gn.District_Name)}</p>
                                    <p class="mb-0"><strong>Province:</strong> ${escapeHtml(slip.to_gn.Province_Name)}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Family Details -->
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="mb-0"><i class="bi bi-house-door"></i> FAMILY DETAILS</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p><strong>Head of Family:</strong> ${escapeHtml(slip.family_head)}</p>
                                            <p><strong>Head NIC:</strong> ${escapeHtml(slip.family_head_nic)}</p>
                                            <p><strong>Total Members:</strong> ${slip.total_members}</p>
                                        </div>
                                        <div class="col-md-6">
                                            <p><strong>Current Address:</strong><br>
                                            ${escapeHtml(slip.current_address).replace(/\n/g, '<br>')}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Family Members Table -->
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0"><i class="bi bi-people"></i> FAMILY MEMBERS</h6>
                                </div>
                                <div class="card-body p-0">
                                    <table class="table table-bordered mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>#</th>
                                                <th>Name</th>
                                                <th>NIC/ID</th>
                                                <th>Relation</th>
                                                <th>Gender</th>
                                                <th>Date of Birth</th>
                                                <th>Contact</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${membersRows}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Land Details Table -->
                    ${landSection}
                    
                    <!-- Important Instructions -->
                    <div class="row mt-4">
                        <div class="col-md-12">
                            <div class="alert alert-warning">
                                <h6><i class="bi bi-info-circle"></i> IMPORTANT INSTRUCTIONS:</h6>
                                <ol class="mb-0">
                                    <li>This transfer slip must be presented to the receiving GN office</li>
                                    <li>The receiving office must update the family's GN ID in their system</li>
                                    <li>Family ID and member IDs remain the same after transfer</li>
                                    <li>Original documents should be verified at the receiving office</li>
                                    <li>This transfer requires approval from divisional secretariat</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Signatures -->
                    <div class="row mt-4">
                        <div class="col-md-6 text-center">
                            <hr>
                            <p><strong>Requesting Officer Signature</strong></p>
                            <p>Name: ${escapeHtml(slip.requested_by)}</p>
                            <p>Date: ${new Date().toLocaleDateString('en-GB')}</p>
                        </div>
                        <div class="col-md-6 text-center">
                            <hr>
                            <p><strong>Receiving Officer Signature</strong></p>
                            <p>Name: _________________________</p>
                            <p>Date: _________________________</p>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="row mt-4 no-print">
                        <div class="col-md-12 text-center">
                            <a href="list_families.php" class="btn btn-primary me-2">
                                <i class="bi bi-list"></i> Back to Family List
                            </a>
                            <button class="btn btn-warning" onclick="window.print()">
                                <i class="bi bi-printer"></i> Print Slip
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

// Helper function to format date
function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-GB'); // DD/MM/YYYY format
}