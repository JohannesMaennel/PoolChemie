const forecast = JSON.parse(
    document.getElementById("forecastData").textContent
);

const chart =
    document.getElementById("chart");

const maxValue =
    Math.max(
        ...forecast.map(x => x.pv)
    );

const svgWidth = 750;
const svgHeight = 320;

let svg = `
<svg
    viewBox="0 0 ${svgWidth} ${svgHeight}"
    style="width:100%;height:auto;">

<line
    x1="40"
    y1="260"
    x2="740"
    y2="260"
    stroke="#999"/>
`;

forecast.forEach((day,index) => {

    const x = 70 + (index * 95);

    const pvHeight =
        (day.pv / maxValue) * 180;

    const surplusHeight =
        (day.surplus / maxValue) * 180;

    svg += `

    <rect
        x="${x}"
        y="${260-pvHeight}"
        width="40"
        height="${pvHeight}"
        fill="#FFC107">

        <title>
PV: ${day.pv} kWh
Verbrauch: ${day.consumption} kWh
Überschuss: ${day.surplus} kWh
        </title>

    </rect>

    <rect
        x="${x+45}"
        y="${260-surplusHeight}"
        width="25"
        height="${surplusHeight}"
        fill="#4CAF50">

        <title>
PV: ${day.pv} kWh
Verbrauch: ${day.consumption} kWh
Überschuss: ${day.surplus} kWh
        </title>

    </rect>

    <text
        x="${x+20}"
        y="285"
        text-anchor="middle"
        font-size="12">

        ${day.day}

    </text>

    <text
        x="${x+20}"
        y="${245-pvHeight}"
        text-anchor="middle"
        font-size="11">

        ${day.pv.toFixed(0)}

    </text>
    `;
});

svg += '</svg>';

chart.innerHTML = svg;