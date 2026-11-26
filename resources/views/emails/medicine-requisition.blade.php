<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medicine Requisition Request - TCC Clinic</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f7f4ee; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #292524;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f7f4ee; padding: 30px 15px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" style="max-width: 600px; background-color: #ffffff; border-radius: 16px; overflow: hidden; border: 1px solid #e7ded4; box-shadow: 0 4px 16px rgba(0,0,0,0.06);">
                    
                    <!-- Header -->
                    <tr>
                        <td style="background-color: #70091f; padding: 24px 28px; text-align: left;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td>
                                        <p style="margin: 0; font-size: 11px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: #f8a598;">
                                            Tagoloan Community College
                                        </p>
                                        <h1 style="margin: 4px 0 0 0; font-size: 20px; font-weight: 800; color: #ffffff;">
                                            Clinic &amp; Health Services
                                        </h1>
                                        <p style="margin: 4px 0 0 0; font-size: 13px; color: #f4ecdf; opacity: 0.9;">
                                            Official Medicine Requisition &amp; Stock Replenishment Request
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Reference & Priority Sub-header -->
                    <tr>
                        <td style="background-color: #fbf9f5; border-bottom: 1px solid #e7ded4; padding: 14px 28px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="left">
                                        <span style="font-size: 12px; color: #78716c; font-weight: 600;">Reference:</span>
                                        <strong style="font-size: 13px; color: #1c1917; margin-left: 4px;">{{ $ref_number }}</strong>
                                    </td>
                                    <td align="right">
                                        @php
                                            $p = strtolower($requisition->priority);
                                            $bg = '#f3f4f6';
                                            $tc = '#374151';
                                            $border = '#d1d5db';
                                            if ($p === 'critical') {
                                                $bg = '#fee2e2';
                                                $tc = '#991b1b';
                                                $border = '#f87171';
                                            } elseif ($p === 'urgent') {
                                                $bg = '#fef3c7';
                                                $tc = '#92400e';
                                                $border = '#f59e0b';
                                            }
                                        @endphp
                                        <span style="display: inline-block; padding: 4px 12px; font-size: 11px; font-weight: 800; text-transform: uppercase; border-radius: 9999px; background-color: {{ $bg }}; color: {{ $tc }}; border: 1px solid {{ $border }};">
                                            Priority: {{ strtoupper($requisition->priority) }}
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 28px;">
                            <p style="margin: 0 0 20px 0; font-size: 14px; line-height: 1.5; color: #44403c;">
                                Good day,
                            </p>
                            <p style="margin: 0 0 20px 0; font-size: 14px; line-height: 1.5; color: #44403c;">
                                An official stock replenishment request has been submitted through the <strong>TCC Clinic Management System</strong>. Please review the requisition details below for procurement and dispensing fulfillment:
                            </p>

                            <!-- Medicine Details Card -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #faf7f2; border: 1px solid #ebd8c8; border-radius: 12px; margin-bottom: 24px; overflow: hidden;">
                                <tr>
                                    <td colspan="2" style="background-color: #f2e9dc; padding: 12px 18px; border-bottom: 1px solid #ebd8c8;">
                                        <h2 style="margin: 0; font-size: 15px; font-weight: 800; color: #70091f;">
                                            {{ $medicine->medicine_name }}
                                        </h2>
                                        <span style="font-size: 12px; color: #78716c;">
                                            Category: {{ $medicine->treatment_type ?? 'Medication' }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px 18px; border-bottom: 1px solid #ebd8c8; font-size: 13px; color: #78716c; width: 40%;">
                                        Requested Quantity:
                                    </td>
                                    <td style="padding: 10px 18px; border-bottom: 1px solid #ebd8c8; font-size: 14px; font-weight: 800; color: #70091f;">
                                        {{ number_format($requisition->quantity_requested) }} {{ $requisition->unit }}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px 18px; border-bottom: 1px solid #ebd8c8; font-size: 13px; color: #78716c;">
                                        Current In-Stock:
                                    </td>
                                    <td style="padding: 10px 18px; border-bottom: 1px solid #ebd8c8; font-size: 13px; font-weight: 700; color: {{ ($medicine->stock ?? 0) <= 0 ? '#b91c1c' : '#44403c' }};">
                                        {{ $medicine->stock ?? 0 }} {{ $requisition->unit }}
                                        @if(($medicine->stock ?? 0) <= 0)
                                            <span style="color: #b91c1c; font-size: 11px; font-weight: 800;">(OUT OF STOCK)</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px 18px; border-bottom: 1px solid #ebd8c8; font-size: 13px; color: #78716c;">
                                        Alert Minimum Level:
                                    </td>
                                    <td style="padding: 10px 18px; border-bottom: 1px solid #ebd8c8; font-size: 13px; color: #44403c;">
                                        {{ $medicine->minimum_stock ?? 10 }} {{ $requisition->unit }}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px 18px; border-bottom: 1px solid #ebd8c8; font-size: 13px; color: #78716c;">
                                        Purpose / Reason:
                                    </td>
                                    <td style="padding: 10px 18px; border-bottom: 1px solid #ebd8c8; font-size: 13px; font-weight: 600; color: #1c1917;">
                                        {{ $requisition->reason }}
                                    </td>
                                </tr>
                                @if(!empty($requisition->remarks))
                                <tr>
                                    <td style="padding: 10px 18px; font-size: 13px; color: #78716c; vertical-align: top;">
                                        Supplier / Special Notes:
                                    </td>
                                    <td style="padding: 10px 18px; font-size: 13px; color: #44403c; line-height: 1.4;">
                                        {{ $requisition->remarks }}
                                    </td>
                                </tr>
                                @endif
                            </table>

                            <!-- Requisition Metadata -->
                            <div style="background-color: #f5f2eb; border-radius: 10px; padding: 14px 18px; margin-bottom: 24px; font-size: 12px; line-height: 1.6; color: #57534e;">
                                <div><strong>Attending Nurse / Requested By:</strong> {{ $requester_name ?? 'Clinic Personnel' }}</div>
                                @if(!empty($requester_email))
                                    <div><strong>Requester Email:</strong> <a href="mailto:{{ $requester_email }}" style="color: #70091f; text-decoration: none;">{{ $requester_email }}</a></div>
                                @endif
                                <div><strong>Date &amp; Time Logged:</strong> {{ $date_time }}</div>
                                <div><strong>Clinic Location:</strong> Tagoloan Community College Clinic, Baluarte, Tagoloan</div>
                            </div>

                            <p style="margin: 0; font-size: 13px; line-height: 1.5; color: #78716c;">
                                Please coordinate with the clinic administrator or attending nurse upon approval or delivery of the requested supply.
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f5efe6; border-top: 1px solid #e7ded4; padding: 20px 28px; text-align: center;">
                            <p style="margin: 0; font-size: 12px; font-weight: 700; color: #70091f;">
                                Tagoloan Community College &bull; Health Services Unit
                            </p>
                            <p style="margin: 4px 0 0 0; font-size: 11px; color: #a8a29e;">
                                This is an automated email from the TCC Clinic Management System. Please do not reply directly to this email unless directed.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
