from pathlib import Path
from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.platypus import (
    SimpleDocTemplate,
    Paragraph,
    Spacer,
    Table,
    TableStyle,
    Image,
    KeepTogether,
)
import matplotlib.pyplot as plt


ROOT = Path(r"D:/XAMPP/htdocs/saloon")
OUT_DIR = ROOT / "output" / "pdf"
TMP_DIR = ROOT / "tmp" / "pdfs"
OUT_DIR.mkdir(parents=True, exist_ok=True)
TMP_DIR.mkdir(parents=True, exist_ok=True)

PDF_PATH = OUT_DIR / "reports-summary-beautiful.pdf"


metrics = [
    ("Appointments", "10"),
    ("Revenue", "$320"),
    ("New Customers", "27"),
    ("Inventory Items", "817"),
    ("Low Stock", "814"),
    ("Loyalty Members", "20"),
    ("Points Issued", "30"),
    ("Late Minutes", "392"),
]

status_rows = [
    ("Confirmed", 2),
    ("In Progress", 5),
    ("Completed", 3),
]

service_rows = [
    ("Acrylic gel refill", 2, 0),
    ("Baby Polish", 2, 120),
    ("Bridal Hairstyle", 1, 0),
    ("Fix & Remove Hair extension", 1, 0),
    ("Root color", 1, 0),
    ("test", 1, 200),
    ("Blowdry Curly/wavy Iron Short", 1, 0),
    ("Basic Manicure", 1, 0),
]

staff_rows = [
    ("Jocelyn Caburnay Caquista", 3),
    ("Mona Bassagh", 2),
    ("Sahar Shams", 2),
    ("Sara Khan", 1),
    ("Majd Alabaza", 1),
    ("Dulce Aguilar", 1),
]

daily_revenue = [
    ("2026-04-20", 200),
    ("2026-04-22", 120),
]


def make_status_chart(path: Path) -> None:
    labels = [r[0] for r in status_rows]
    vals = [r[1] for r in status_rows]
    colorset = ["#EAB308", "#3B82F6", "#10B981"]
    plt.figure(figsize=(5.2, 3.2), dpi=180)
    bars = plt.bar(labels, vals, color=colorset, width=0.55)
    plt.title("Appointment Status", fontsize=12, fontweight="bold")
    plt.grid(axis="y", alpha=0.2)
    plt.gca().spines[["top", "right", "left"]].set_visible(False)
    plt.gca().tick_params(axis="y", length=0)
    plt.ylabel("Count")
    for b, v in zip(bars, vals):
        plt.text(b.get_x() + b.get_width() / 2, v + 0.08, str(v), ha="center", va="bottom", fontsize=9)
    plt.tight_layout()
    plt.savefig(path, bbox_inches="tight", facecolor="white")
    plt.close()


def make_revenue_chart(path: Path) -> None:
    dates = [r[0][5:] for r in daily_revenue]
    vals = [r[1] for r in daily_revenue]
    plt.figure(figsize=(5.2, 3.2), dpi=180)
    plt.plot(dates, vals, marker="o", linewidth=2.5, color="#EC4899")
    plt.fill_between(dates, vals, color="#FBCFE8", alpha=0.6)
    plt.title("Daily Revenue", fontsize=12, fontweight="bold")
    plt.ylabel("USD")
    plt.grid(alpha=0.2)
    plt.gca().spines[["top", "right"]].set_visible(False)
    for x, y in zip(dates, vals):
        plt.text(x, y + 4, f"${y}", ha="center", fontsize=9)
    plt.tight_layout()
    plt.savefig(path, bbox_inches="tight", facecolor="white")
    plt.close()


def make_services_chart(path: Path) -> None:
    rows = sorted(service_rows, key=lambda r: r[2], reverse=True)[:6]
    labels = [r[0] for r in rows][::-1]
    vals = [r[2] for r in rows][::-1]
    plt.figure(figsize=(6, 3.6), dpi=180)
    bars = plt.barh(labels, vals, color="#8B5CF6")
    plt.title("Top Service Revenue", fontsize=12, fontweight="bold")
    plt.xlabel("USD")
    plt.gca().spines[["top", "right", "left"]].set_visible(False)
    plt.grid(axis="x", alpha=0.2)
    for b, v in zip(bars, vals):
        plt.text(v + 3, b.get_y() + b.get_height() / 2, f"${v}", va="center", fontsize=9)
    plt.tight_layout()
    plt.savefig(path, bbox_inches="tight", facecolor="white")
    plt.close()


def make_staff_chart(path: Path) -> None:
    labels = [r[0].split()[0] for r in staff_rows]
    vals = [r[1] for r in staff_rows]
    plt.figure(figsize=(6, 3.4), dpi=180)
    bars = plt.bar(labels, vals, color="#0EA5E9")
    plt.title("Top Staff by Appointments", fontsize=12, fontweight="bold")
    plt.ylabel("Appointments")
    plt.gca().spines[["top", "right", "left"]].set_visible(False)
    plt.grid(axis="y", alpha=0.2)
    plt.gca().tick_params(axis="y", length=0)
    for b, v in zip(bars, vals):
        plt.text(b.get_x() + b.get_width() / 2, v + 0.05, str(v), ha="center", fontsize=9)
    plt.tight_layout()
    plt.savefig(path, bbox_inches="tight", facecolor="white")
    plt.close()


status_chart = TMP_DIR / "status_chart.png"
revenue_chart = TMP_DIR / "revenue_chart.png"
services_chart = TMP_DIR / "services_chart.png"
staff_chart = TMP_DIR / "staff_chart.png"
make_status_chart(status_chart)
make_revenue_chart(revenue_chart)
make_services_chart(services_chart)
make_staff_chart(staff_chart)


styles = getSampleStyleSheet()
styles.add(ParagraphStyle(name="TitleXL", fontName="Helvetica-Bold", fontSize=22, leading=26, textColor=colors.HexColor("#111827")))
styles.add(ParagraphStyle(name="Muted", fontName="Helvetica", fontSize=9.5, leading=13, textColor=colors.HexColor("#6B7280")))
styles.add(ParagraphStyle(name="Section", fontName="Helvetica-Bold", fontSize=12, leading=15, textColor=colors.HexColor("#0F172A")))
styles.add(ParagraphStyle(name="CardLabel", fontName="Helvetica", fontSize=8.5, leading=10, textColor=colors.HexColor("#64748B"), alignment=1))
styles.add(ParagraphStyle(name="CardValue", fontName="Helvetica-Bold", fontSize=17, leading=20, textColor=colors.HexColor("#111827"), alignment=1))


def metric_card(label: str, value: str, bg: str):
    t = Table(
        [[Paragraph(label.upper(), styles["CardLabel"])], [Paragraph(value, styles["CardValue"])]],
        colWidths=[42 * mm],
        rowHeights=[10 * mm, 12 * mm],
    )
    t.setStyle(
        TableStyle(
            [
                ("BACKGROUND", (0, 0), (-1, -1), colors.HexColor(bg)),
                ("BOX", (0, 0), (-1, -1), 0, colors.HexColor(bg)),
                ("LEFTPADDING", (0, 0), (-1, -1), 8),
                ("RIGHTPADDING", (0, 0), (-1, -1), 8),
                ("TOPPADDING", (0, 0), (-1, -1), 7),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 7),
                ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
            ]
        )
    )
    return t


card_colors = ["#FCE7F3", "#E0F2FE", "#ECFCCB", "#FEF3C7", "#FEE2E2", "#EDE9FE", "#DBEAFE", "#DCFCE7"]
cards = [metric_card(label, value, card_colors[i % len(card_colors)]) for i, (label, value) in enumerate(metrics)]
card_grid = []
for i in range(0, len(cards), 4):
    row = cards[i:i + 4]
    if len(row) < 4:
        row += [""] * (4 - len(row))
    card_grid.append(row)


service_table_data = [["Service", "Appointments", "Revenue"]]
for name, appts, revenue in service_rows:
    service_table_data.append([name, str(appts), f"${revenue:.2f}"])

staff_table_data = [["Staff", "Appointments"]]
for name, appts in staff_rows:
    staff_table_data.append([name, str(appts)])

revenue_table_data = [["Date", "Revenue"]]
for date, revenue in daily_revenue:
    revenue_table_data.append([date, f"${revenue:.2f}"])


doc = SimpleDocTemplate(
    str(PDF_PATH),
    pagesize=A4,
    leftMargin=14 * mm,
    rightMargin=14 * mm,
    topMargin=14 * mm,
    bottomMargin=14 * mm,
)

story = []
story.append(Paragraph("Vina Operations Summary", styles["TitleXL"]))
story.append(Spacer(1, 3 * mm))
story.append(Paragraph("Reporting period: 2026-04-01 to 2026-04-25", styles["Muted"]))
story.append(Spacer(1, 6 * mm))
story.append(
    Paragraph(
        "A cleaner management snapshot with key business metrics, service performance, staff activity, and revenue visuals.",
        styles["Muted"],
    )
)
story.append(Spacer(1, 6 * mm))

cards_table = Table(card_grid, colWidths=[43 * mm] * 4, rowHeights=[26 * mm] * 2, hAlign="LEFT")
cards_table.setStyle(TableStyle([("LEFTPADDING", (0, 0), (-1, -1), 3), ("RIGHTPADDING", (0, 0), (-1, -1), 3), ("BOTTOMPADDING", (0, 0), (-1, -1), 3), ("TOPPADDING", (0, 0), (-1, -1), 3)]))
story.append(cards_table)
story.append(Spacer(1, 7 * mm))

story.append(
    KeepTogether(
        [
            Table(
                [[Image(str(status_chart), width=84 * mm, height=52 * mm), Image(str(revenue_chart), width=84 * mm, height=52 * mm)]],
                colWidths=[88 * mm, 88 * mm],
            )
        ]
    )
)
story.append(Spacer(1, 6 * mm))

summary_note = Table(
    [[Paragraph("<b>Highlights</b><br/>- In-progress appointments represent the largest active workload.<br/>- Revenue is concentrated in two recorded days, with `test` and `Baby Polish` driving all booked revenue in this extract.<br/>- Low stock is critically high versus total inventory and needs immediate replenishment review.", styles["Muted"])]],
    colWidths=[178 * mm],
)
summary_note.setStyle(TableStyle([("BACKGROUND", (0, 0), (-1, -1), colors.HexColor("#F8FAFC")), ("BOX", (0, 0), (-1, -1), 0.6, colors.HexColor("#E2E8F0")), ("LEFTPADDING", (0, 0), (-1, -1), 10), ("RIGHTPADDING", (0, 0), (-1, -1), 10), ("TOPPADDING", (0, 0), (-1, -1), 10), ("BOTTOMPADDING", (0, 0), (-1, -1), 10)]))
story.append(summary_note)
story.append(Spacer(1, 8 * mm))

story.append(Paragraph("Performance Detail", styles["Section"]))
story.append(Spacer(1, 3 * mm))
story.append(
    Table(
        [[Image(str(services_chart), width=88 * mm, height=54 * mm), Image(str(staff_chart), width=88 * mm, height=54 * mm)]],
        colWidths=[88 * mm, 88 * mm],
    )
)
story.append(Spacer(1, 6 * mm))

service_table = Table(service_table_data, colWidths=[106 * mm, 30 * mm, 42 * mm])
service_table.setStyle(
    TableStyle(
        [
            ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#111827")),
            ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
            ("FONTNAME", (0, 0), (-1, 0), "Helvetica-Bold"),
            ("BACKGROUND", (0, 1), (-1, -1), colors.white),
            ("GRID", (0, 0), (-1, -1), 0.4, colors.HexColor("#CBD5E1")),
            ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, colors.HexColor("#F8FAFC")]),
            ("ALIGN", (1, 1), (-1, -1), "CENTER"),
            ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
            ("FONTSIZE", (0, 0), (-1, -1), 9),
            ("LEFTPADDING", (0, 0), (-1, -1), 7),
            ("RIGHTPADDING", (0, 0), (-1, -1), 7),
            ("TOPPADDING", (0, 0), (-1, -1), 6),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
        ]
    )
)
story.append(service_table)
story.append(Spacer(1, 6 * mm))

story.append(Paragraph("Team and Revenue Breakdown", styles["Section"]))
story.append(Spacer(1, 2 * mm))

detail_note = Table(
    [[Paragraph("The service table shows a wide mix of bookings, but revenue is concentrated in a very small subset of services. Staff distribution is relatively even after the top performer, while revenue reporting currently shows only two active booking days in the extracted range.", styles["Muted"])]],
    colWidths=[178 * mm],
)
detail_note.setStyle(TableStyle([("BACKGROUND", (0, 0), (-1, -1), colors.HexColor("#F8FAFC")), ("BOX", (0, 0), (-1, -1), 0.6, colors.HexColor("#E2E8F0")), ("LEFTPADDING", (0, 0), (-1, -1), 10), ("RIGHTPADDING", (0, 0), (-1, -1), 10), ("TOPPADDING", (0, 0), (-1, -1), 8), ("BOTTOMPADDING", (0, 0), (-1, -1), 8)]))
story.append(detail_note)
story.append(Spacer(1, 5 * mm))

bottom_tables = Table(
    [
        [
            Table(staff_table_data, colWidths=[70 * mm, 20 * mm]),
            Table(revenue_table_data, colWidths=[52 * mm, 28 * mm]),
        ]
    ],
    colWidths=[94 * mm, 84 * mm],
)
bottom_tables.setStyle(TableStyle([("VALIGN", (0, 0), (-1, -1), "TOP")]))

for tbl in [bottom_tables._cellvalues[0][0], bottom_tables._cellvalues[0][1]]:
    tbl.setStyle(
        TableStyle(
            [
                ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#1D4ED8")),
                ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
                ("FONTNAME", (0, 0), (-1, 0), "Helvetica-Bold"),
                ("GRID", (0, 0), (-1, -1), 0.4, colors.HexColor("#CBD5E1")),
                ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, colors.HexColor("#EFF6FF")]),
                ("FONTSIZE", (0, 0), (-1, -1), 9),
                ("LEFTPADDING", (0, 0), (-1, -1), 7),
                ("RIGHTPADDING", (0, 0), (-1, -1), 7),
                ("TOPPADDING", (0, 0), (-1, -1), 6),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
            ]
        )
    )

story.append(bottom_tables)


def draw_page(canvas, doc):
    canvas.saveState()
    canvas.setFillColor(colors.HexColor("#94A3B8"))
    canvas.setFont("Helvetica", 8)
    canvas.drawRightString(A4[0] - 14 * mm, 8 * mm, f"Page {doc.page}")
    canvas.drawString(14 * mm, 8 * mm, "Vina Management System")
    canvas.restoreState()


doc.build(story, onFirstPage=draw_page, onLaterPages=draw_page)
print(PDF_PATH)
