package com.example.views;

import com.vaadin.flow.component.html.Label;
import com.vaadin.flow.component.orderedlayout.VerticalLayout;
import com.vaadin.flow.router.Route;
import com.vaadin.flow.router.PageTitle;
import com.vaadin.flow.component.dependency.CssImport;
import com.vaadin.flow.component.dependency.JsModule;
import com.vaadin.flow.component.dependency.NpmPackage;
import com.vaadin.flow.component.dependency.StyleSheet;

@Route("monitoring")
@PageTitle("Monitoring")
@CssImport("./styles/shared-styles.css")
@JsModule("@vaadin/vaadin-lumo-styles/presets/compact.js")
@NpmPackage(value = "@fontsource/roboto", version = "4.5.0")
@StyleSheet("https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500&display=swap")
public class MonitoringView extends VerticalLayout {

    public MonitoringView() {
        Label monitoringLabel = new Label("Monitoring View");
        add(monitoringLabel);
    }
}
